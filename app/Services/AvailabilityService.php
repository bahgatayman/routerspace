<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The single source of truth for "is this room available." Both exclusive
 * rooms (meeting/training/office) and capacity-based shared rooms are
 * answered by the same capacity-sum formula — an exclusive room is simply a
 * shared room whose effective capacity is pinned to 1
 * (see Room::effectiveCapacity()) — so there is one engine, not two parallel
 * conflict systems. The calendar and the booking form both call into this
 * service; neither computes availability on its own.
 */
class AvailabilityService
{
    /**
     * Fallback display bounds for an owner who hasn't configured working
     * hours yet (BusinessHoursService::hasConfiguredHours() === false) —
     * the same 06:00-23:30 window this service always used before Working
     * Hours existed. Once an owner configures real hours, freeBusyForDay()/
     * bookableSlots() switch to BusinessHoursService::effectiveWindowForDate()
     * instead: it, not these constants, is this app's single source of
     * truth for "is the workspace open."
     */
    public const OPERATING_START = '06:00';

    public const OPERATING_END = '23:30';

    /** Booking statuses that never hold capacity (everything else does). */
    public const NON_BLOCKING_STATUSES = ['cancelled', 'no_show'];

    /** Longest date range rangeReport() answers in one call. */
    public const MAX_RANGE_DAYS = 31;

    public function __construct(private BusinessHoursService $businessHours) {}

    /**
     * Capacity already consumed by bookings overlapping the given
     * [start, end) window on that date. Mirrors Room::hasConflict()'s exact
     * overlap predicate (start < end AND end > start — touching bounds are
     * not conflicts) and exclude semantics, but sums party_size instead of
     * returning a boolean.
     *
     * Excludes 'cancelled' and 'no_show' — a booking the no-show sweep has
     * already flipped correctly stops holding its slot here. This is the
     * future-window/calendar formula: it does NOT additionally live-check
     * Booking::isPastNoShowGrace() the way usedCapacityNow() does. A
     * reservation that's merely past-grace-but-not-yet-swept still holds
     * its own slot in the calendar and against new advance-booking
     * conflicts for a few minutes until the sweep catches up — the live,
     * no-scheduler-dependency check that matters for real-time safety
     * (don't let a stale reservation block a walk-in RIGHT NOW) lives in
     * usedCapacityNow() and the check-in endpoint's own explicit check,
     * not here.
     */
    public function usedCapacity(Room $room, string $date, string $start, string $end, ?int $excludeBookingId = null): int
    {
        // whereDate(), not where('booking_date', $date) — see the identical
        // comment on Room::hasConflict() for why a bare string comparison
        // silently fails to match on engines without native DATE coercion.
        return (int) $room->bookings()
            ->whereDate('booking_date', $date)
            ->whereNotIn('status', self::NON_BLOCKING_STATUSES)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where(function ($q) use ($start, $end) {
                // An Open Session booking has no predictable end (end_time
                // IS NULL) — 'end_time > $start' would evaluate to SQL NULL
                // for that row and silently exclude it, so a new booking
                // could wrongly be allowed to overlap a room that's actually
                // occupied right now. It has no future end to range-check
                // against, so it always counts instead.
                $q->where(fn ($q2) => $q2->where('start_time', '<', $end)->where('end_time', '>', $start))
                    ->orWhere('status', 'open');
            })
            ->sum('party_size');
    }

    /**
     * Seats/capacity still free for that window. For an exclusive room
     * (effectiveCapacity=1), any single overlapping booking already exhausts
     * it, so this collapses to exactly today's hasConflict() boolean outcome
     * — just computed as one general formula instead of a separate check.
     */
    public function availabilityForRange(Room $room, string $date, string $start, string $end, ?int $excludeBookingId = null): int
    {
        $used = $this->usedCapacity($room, $date, $start, $end, $excludeBookingId);

        return max(0, $room->effectiveCapacity() - $used);
    }

    /**
     * Post-write defense-in-depth check: has this room's actual, currently
     * committed usage for this window exceeded its capacity? Deliberately
     * takes no excludeBookingId — it measures the real resulting state,
     * including whatever row was just written, not a hypothetical "would
     * this fit" check. Callers use this to verify their own write didn't
     * push the room over capacity, independent of whether the row lock
     * taken before the write actually serialized concurrent requests.
     */
    public function exceedsCapacity(Room $room, string $date, string $start, string $end): bool
    {
        return $this->usedCapacity($room, $date, $start, $end) > $room->effectiveCapacity();
    }

    /**
     * Free/busy blocks for a room on one date. When the owner has
     * configured working hours (BusinessHoursService::hasConfiguredHours()),
     * the displayed window starts/ends exactly at that day's open hours
     * (effectiveWindowForDate()) rather than spanning the full 24 hours —
     * a whole closed morning/night as dead space just forces excessive
     * scrolling on the calendar for no benefit. A genuine gap *between* two
     * open segments (e.g. the overnight-then-reopens case) still renders as
     * 'closed', since it falls inside the displayed range either way. An
     * owner who hasn't configured hours yet keeps exactly the old
     * OPERATING_START/OPERATING_END-bounded behavior, with every segment's
     * 'closed' key simply always false.
     *
     * Computed with a sweep over capacity-change events (each booking
     * contributes a +party_size at its start and a -party_size at its end)
     * rather than a simple two-state merge, so it's correct for
     * capacity-based shared rooms too, not just exclusive ones — a segment
     * is "free" only once every overlapping booking's party_size has been
     * accounted for, and adjacent segments with the same used-capacity/
     * closed state are merged so the output isn't needlessly fragmented.
     *
     * A booking that falls outside the currently-configured hours (e.g. one
     * created before hours were configured, or before the owner narrowed
     * them) is never hidden — the displayed window expands (never shrinks)
     * to cover it, and its segment still reports the true 'used' figure,
     * only 'available' is forced to 0, since closed hours mean no NEW
     * booking can be placed there regardless of what already exists.
     *
     * @return array<int, array{start: string, end: string, used: int, capacity: int, available: int, closed: bool}>
     */
    public function freeBusyForDay(Room $room, string $date): array
    {
        $capacity = $room->effectiveCapacity();

        // Same future-window semantics as usedCapacity() — see its docblock
        // for why this deliberately does not live-check no-show grace.
        $bookings = $room->bookings()
            ->whereDate('booking_date', $date)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->get(['start_time', 'end_time', 'party_size']);

        $hasConfiguredHours = $this->businessHours->hasConfiguredHours($room->owner);

        if ($hasConfiguredHours) {
            $openRanges = collect($this->businessHours->effectiveWindowForDate($room->owner, $date))
                ->map(fn ($segment) => [$this->toMinutes($segment[0]), $this->toMinutes($segment[1])]);

            // The displayed window starts/ends exactly at the open hours,
            // not a fixed full-day span — always showing a whole closed
            // morning/night as dead space forced excessive scrolling on the
            // calendar for no benefit. It's still expanded (never shrunk)
            // to cover any actual booking's own time, even one that now
            // falls outside the currently-configured hours (e.g. created
            // before hours existed) — that booking must stay visible, not
            // get silently scrolled out of the displayed range.
            $candidateBounds = $openRanges->flatten()
                ->merge($bookings->map(fn ($b) => $this->toMinutes((string) $b->start_time)))
                ->merge($bookings->map(fn ($b) => $this->toMinutes((string) $b->end_time)));

            if ($candidateBounds->isEmpty()) {
                // Fully closed day, nothing booked either — nothing to
                // anchor a scale to; fall back to the legacy display window
                // so the bar still renders one (closed) segment instead of
                // an empty result.
                $dayStartMin = $this->toMinutes(self::OPERATING_START);
                $dayEndMin = $this->toMinutes(self::OPERATING_END);
            } else {
                $dayStartMin = $candidateBounds->min();
                $dayEndMin = $candidateBounds->max();
            }
        } else {
            $dayStartMin = $this->toMinutes(self::OPERATING_START);
            $dayEndMin = $this->toMinutes(self::OPERATING_END);
            $openRanges = null;
        }

        $boundaries = collect([$dayStartMin, $dayEndMin]);
        foreach ($bookings as $booking) {
            $boundaries->push(max($dayStartMin, $this->toMinutes((string) $booking->start_time)));
            $boundaries->push(min($dayEndMin, $this->toMinutes((string) $booking->end_time)));
        }
        if ($openRanges !== null) {
            foreach ($openRanges as [$rangeStart, $rangeEnd]) {
                $boundaries->push(max($dayStartMin, $rangeStart));
                $boundaries->push(min($dayEndMin, $rangeEnd));
            }
        }

        $times = $boundaries->unique()->sort()->values();

        $segments = [];
        for ($i = 0; $i < $times->count() - 1; $i++) {
            $segStartMin = $times[$i];
            $segEndMin = $times[$i + 1];

            if ($segStartMin >= $segEndMin) {
                continue;
            }

            $used = 0;
            foreach ($bookings as $booking) {
                $bookingStartMin = $this->toMinutes((string) $booking->start_time);
                $bookingEndMin = $this->toMinutes((string) $booking->end_time);
                if ($bookingStartMin < $segEndMin && $bookingEndMin > $segStartMin) {
                    $used += (int) $booking->party_size;
                }
            }

            $closed = $openRanges !== null && ! $openRanges->contains(
                fn ($range) => $segStartMin >= $range[0] && $segEndMin <= $range[1]
            );

            $segments[] = [
                'start' => $this->fromMinutes($segStartMin),
                'end' => $this->fromMinutes($segEndMin),
                'used' => $used,
                'capacity' => $capacity,
                'available' => $closed ? 0 : max(0, $capacity - $used),
                'closed' => $closed,
            ];
        }

        // Merge adjacent segments that ended up with the same usage/closed
        // state, so a room with no bookings reports one clean gap per open
        // window instead of one segment per internal boundary point.
        $merged = [];
        foreach ($segments as $segment) {
            $last = end($merged);
            if ($last !== false && $last['used'] === $segment['used'] && $last['closed'] === $segment['closed'] && $last['end'] === $segment['start']) {
                $merged[array_key_last($merged)]['end'] = $segment['end'];
            } else {
                $merged[] = $segment;
            }
        }

        return $merged;
    }

    /**
     * Multi-day availability for the same daily window ($start → $end) on
     * every date from $from to $to, for each room — one query for all of it.
     *
     * Uses exactly usedCapacity()/availabilityForRange()'s rules (same
     * non-blocking statuses, same "start < end AND end > start" overlap with
     * touching bounds allowed, Open Session bookings always counting on
     * their date, Room::effectiveCapacity() for exclusive vs shared) plus
     * BusinessHoursService for working hours — `remaining` per day is the
     * very number store() would check, so this page can never disagree with
     * an actual booking attempt. On top it explains *why*: the conflicting
     * bookings and a free/booked split of the requested window.
     *
     * @param  Collection<int, Room>  $rooms  owner-scoped by the caller
     * @return array<int, array<string, mixed>> one entry per room
     */
    public function rangeReport(Collection $rooms, string $from, string $to, string $start, string $end, int $partySize = 1): array
    {
        $dates = [];
        for ($d = Carbon::parse($from)->startOfDay(), $last = Carbon::parse($to)->startOfDay(); $d->lte($last); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        if (! $rooms->count() || ! $dates) {
            return [];
        }

        $winStart = $this->toMinutes($start);
        $winEnd = $this->toMinutes($end);
        $today = now()->toDateString();
        $owner = $rooms->first()->owner;

        // Every booking that could matter, for every room and day, in one query.
        $bookings = Booking::whereIn('room_id', $rooms->pluck('id'))
            ->whereDate('booking_date', '>=', $dates[0])
            ->whereDate('booking_date', '<=', end($dates))
            ->whereNotIn('status', self::NON_BLOCKING_STATUSES)
            ->with('hotspotUser:id,name')
            ->orderBy('start_time')
            ->get(['id', 'room_id', 'hotspot_user_id', 'booking_date', 'start_time', 'end_time', 'party_size', 'status'])
            ->groupBy(fn (Booking $b) => $b->room_id.'|'.$b->booking_date->toDateString());

        // Working hours: same answer for every room on a given date.
        $open = [];
        foreach ($dates as $date) {
            $open[$date] = $this->businessHours->isWithinWorkingHours($owner, $date, $start, $end);
        }

        $report = [];
        foreach ($rooms as $room) {
            $capacity = $room->effectiveCapacity();
            $needed = $room->isShared() ? max(1, $partySize) : 1;
            $days = [];

            foreach ($dates as $date) {
                // Exactly usedCapacity()'s overlap rule, applied in memory.
                $overlapping = ($bookings[$room->id.'|'.$date] ?? collect())->filter(function (Booking $b) use ($winStart, $winEnd) {
                    if ($b->status === 'open' || $b->end_time === null) {
                        return true;
                    }

                    return $this->toMinutes((string) $b->start_time) < $winEnd && $this->toMinutes((string) $b->end_time) > $winStart;
                })->values();

                $used = (int) $overlapping->sum('party_size');
                $remaining = max(0, $capacity - $used);

                $status = match (true) {
                    $date < $today => 'past',
                    ! $open[$date] => 'closed',
                    $remaining >= $needed => $overlapping->isEmpty() ? 'available' : 'limited',
                    default => 'unavailable',
                };

                $days[] = [
                    'date' => $date,
                    'status' => $status,
                    'bookable' => in_array($status, ['available', 'limited'], true),
                    'capacity' => $capacity,
                    'used' => $used,
                    'remaining' => $remaining,
                    'conflicts' => $overlapping->map(fn (Booking $b) => [
                        'booking_id' => $b->id,
                        'start' => substr((string) $b->start_time, 0, 5),
                        'end' => $b->end_time === null ? null : substr((string) $b->end_time, 0, 5),
                        'status' => $b->status,
                        'customer' => $b->hotspotUser?->name,
                        'party_size' => (int) $b->party_size,
                        // Minutes of the requested window this booking takes.
                        'overlap_minutes' => max(0, min($winEnd, $b->end_time === null ? $winEnd : $this->toMinutes((string) $b->end_time))
                            - max($winStart, $this->toMinutes((string) $b->start_time))),
                    ])->all(),
                    'segments' => $this->windowSegments($overlapping, $winStart, $winEnd, $capacity),
                ];
            }

            $bookable = collect($days)->where('bookable', true)->count();
            $report[] = [
                'room_id' => $room->id,
                'room_name' => $room->name,
                'workspace' => $room->workspace?->name,
                'type' => $room->type,
                'type_label' => $room->typeLabel(),
                'is_shared' => $room->isShared(),
                'capacity' => $capacity,
                'days_total' => count($days),
                'days_available' => $bookable,
                'conflict_days' => collect($days)->where('status', 'unavailable')->count(),
                'all_available' => $bookable === count($days),
                'days' => $days,
            ];
        }

        return $report;
    }

    /**
     * The requested window split at every booking edge: each piece with the
     * capacity used in it (a sweep like freeBusyForDay(), clipped to the
     * window), adjacent pieces with the same usage merged.
     *
     * @return array<int, array{start: string, end: string, used: int, available: int}>
     */
    private function windowSegments(Collection $overlapping, int $winStart, int $winEnd, int $capacity): array
    {
        $points = collect([$winStart, $winEnd]);
        foreach ($overlapping as $b) {
            $points->push(max($winStart, min($winEnd, $this->toMinutes((string) $b->start_time))));
            if ($b->end_time !== null) {
                $points->push(max($winStart, min($winEnd, $this->toMinutes((string) $b->end_time))));
            }
        }
        $points = $points->unique()->sort()->values();

        $segments = [];
        for ($i = 0; $i < $points->count() - 1; $i++) {
            [$a, $z] = [$points[$i], $points[$i + 1]];
            $used = (int) $overlapping->filter(function ($b) use ($a, $z) {
                $bs = $this->toMinutes((string) $b->start_time);
                $be = $b->end_time === null ? 24 * 60 : $this->toMinutes((string) $b->end_time);

                return $bs < $z && $be > $a;
            })->sum('party_size');

            $last = end($segments);
            if ($last !== false && $last['used'] === $used) {
                $segments[array_key_last($segments)]['end'] = $this->fromMinutes($z);
            } else {
                $segments[] = ['start' => $this->fromMinutes($a), 'end' => $this->fromMinutes($z), 'used' => $used, 'available' => max(0, $capacity - $used)];
            }
        }

        return $segments;
    }

    /** Minutes since midnight for an 'H:i' or 'H:i:s' time string, or the '24:00'/'24:00:00' sentinel. */
    private function toMinutes(string $time): int
    {
        if (str_starts_with($time, '24:00')) {
            return 24 * 60;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    /** Inverse of toMinutes(), formatted as 'H:i' (or the '24:00' sentinel). */
    private function fromMinutes(int $minutes): string
    {
        if ($minutes >= 24 * 60) {
            return '24:00';
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * The largest amount of capacity already spoken for by data a room
     * config change must not silently invalidate: the busiest moment across
     * every non-cancelled Booking dated today or later (a past-dated
     * booking is stale history, not ongoing commitment — matches the
     * booking_date >= today rule store() already enforces on write), plus
     * whatever seats are occupied right now by open SharedSessions (which
     * live outside the Booking table entirely, so freeBusyForDay() can't
     * see them and they're checked separately).
     *
     * Deliberately the MAX of the two sources, not their sum: each is its
     * own largest-known simultaneous demand — a specific future date/time
     * for bookings, "right now" for open sessions — and nothing establishes
     * they'd ever be concurrent with each other, so summing would overstate
     * the true constraint. RoomController uses this to block a capacity
     * decrease (or a type change that pins effectiveCapacity() down to 1)
     * that would retroactively make already-committed usage impossible.
     */
    public function peakCommittedUsage(Room $room): int
    {
        $today = now()->format('Y-m-d');

        $futureDates = $room->bookings()
            ->whereDate('booking_date', '>=', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->get(['booking_date'])
            ->map(fn ($b) => $b->booking_date->format('Y-m-d'))
            ->unique();

        $peakBookingUsage = $futureDates
            ->map(fn ($date) => collect($this->freeBusyForDay($room, $date))->max('used') ?? 0)
            ->max() ?? 0;

        $openSessionOccupancy = (int) $room->openSharedSessions()->sum('party_size');

        return max($peakBookingUsage, $openSessionOccupancy);
    }

    /**
     * Fixed-size candidate booking slots for one exclusive room/day, each
     * flagged available or not. Built entirely from freeBusyForDay()'s
     * already-computed segments — no extra queries, and no re-derivation of
     * the capacity/overlap math, which stays solely in usedCapacity(). A
     * slot is available only if every segment it touches is fully free
     * (used === 0): a slot that's merely partially booked is marked
     * unavailable too, since a booking spanning it would fail server-side
     * regardless of the free portion.
     *
     * Shared rooms return an empty list on purpose — advance booking for
     * shared rooms is deferred (Phase 5), so there is no click-to-book
     * target to offer for them here.
     *
     * When the owner has configured working hours, slots are only generated
     * across the envelope of the day's actual open segments (a fully closed
     * day returns no slots at all), and a slot is available only if it also
     * falls entirely inside a single open segment — an owner without
     * configured hours keeps generating across the old fixed
     * OPERATING_START/OPERATING_END window exactly as before.
     */
    public function bookableSlots(Room $room, string $date, int $slotMinutes = 60): array
    {
        if ($room->isShared()) {
            return [];
        }

        $blocks = collect($this->freeBusyForDay($room, $date));

        if ($this->businessHours->hasConfiguredHours($room->owner)) {
            $openRanges = collect($this->businessHours->effectiveWindowForDate($room->owner, $date))
                ->map(fn ($segment) => [$this->toMinutes($segment[0]), $this->toMinutes($segment[1])]);

            if ($openRanges->isEmpty()) {
                return [];
            }

            $dayStartMin = $openRanges->min(fn ($range) => $range[0]);
            $dayEndMin = $openRanges->max(fn ($range) => $range[1]);
        } else {
            $openRanges = null;
            $dayStartMin = $this->toMinutes(self::OPERATING_START);
            $dayEndMin = $this->toMinutes(self::OPERATING_END);
        }

        $slots = [];
        $cursorMin = $dayStartMin;
        while ($cursorMin < $dayEndMin) {
            $slotStartMin = $cursorMin;
            $slotEndMin = min($cursorMin + $slotMinutes, $dayEndMin);

            $withinOpenHours = $openRanges === null || $openRanges->contains(
                fn ($range) => $slotStartMin >= $range[0] && $slotEndMin <= $range[1]
            );

            $available = $withinOpenHours && $blocks
                ->filter(fn ($b) => $this->toMinutes($b['start']) < $slotEndMin && $this->toMinutes($b['end']) > $slotStartMin)
                ->every(fn ($b) => $b['used'] === 0 && ! $b['closed']);

            $slots[] = [
                'start' => $this->fromMinutes($slotStartMin),
                'end' => $this->fromMinutes($slotEndMin),
                'available' => $available,
            ];

            $cursorMin += $slotMinutes;
        }

        return $slots;
    }

    /**
     * Capacity used RIGHT NOW for a shared room — a different formula from
     * usedCapacity()'s future-window one, needed because an open
     * SharedSession has no predictable end time and so can't be reasoned
     * about as "overlapping" some future window the way a Booking can. Two
     * sources, summed:
     *  - today's pending/confirmed bookings whose [start_time, end_time)
     *    window contains this instant — deliberately NOT 'checked_in'
     *    bookings, which are represented by their own open SharedSession
     *    instead; counting both would double-count every currently-seated
     *    reserved party.
     *  - every currently-open SharedSession's party_size.
     * $excludeBookingId lets check-in ask "what's used without the
     * reservation I'm about to convert," so that booking's own (about to
     * be replaced) seats aren't counted against the party actually walking
     * in for it.
     */
    /**
     * Canonical "which bookings overlap this instant" primitive, shared by
     * usedCapacityNow(), OccupancyAnalyticsService::currentOccupancy(), and
     * ActiveSessionsQuery — the three places that used to hand-roll this
     * same predicate independently and had already drifted. Deliberately
     * does NOT apply the no-show-grace rejection itself (see
     * rejectPastGrace()) — that's a capacity/occupancy-specific business
     * rule, not part of "do these time windows overlap right now," and
     * ActiveSessionsQuery's visibility rule intentionally does not apply it
     * at all (a confirmed shared-room booking stays visible until its own
     * end_time, not until the no-show grace expires).
     *
     * @param  int[]|null  $roomIds  Narrow to these rooms, or null for every room the owner has.
     * @return Collection<int, Booking>
     */
    public function bookingsOverlappingNow(int $ownerId, array $statuses, Carbon $now, ?array $roomIds = null, ?int $excludeBookingId = null): Collection
    {
        return Booking::where('owner_id', $ownerId)
            ->when($roomIds !== null, fn ($q) => $q->whereIn('room_id', $roomIds))
            ->whereDate('booking_date', $now->format('Y-m-d'))
            ->whereIn('status', $statuses)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where('start_time', '<=', $now->format('H:i:s'))
            ->where('end_time', '>', $now->format('H:i:s'))
            ->get(['id', 'room_id', 'status', 'booking_date', 'start_time', 'end_time', 'party_size']);
    }

    /**
     * Drop a still-'confirmed' (never checked in) shared-room booking once
     * its no-show grace has elapsed — real-time safety that must not depend
     * on a periodic sweep having already run. $isShared is a callback (not a
     * plain bool) since a multi-room caller's bookings may span both shared
     * and exclusive rooms.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Booking>
     */
    public function rejectPastGrace(Collection $bookings, callable $isShared): Collection
    {
        return $bookings->reject(fn (Booking $b) => $isShared($b) && $b->status === 'confirmed' && $b->isPastNoShowGrace());
    }

    public function usedCapacityNow(Room $room, ?int $excludeBookingId = null): int
    {
        $now = Carbon::now();

        $overlappingNow = $this->bookingsOverlappingNow($room->owner_id, ['pending', 'confirmed'], $now, [$room->id], $excludeBookingId);

        // The live no-show check belongs here, not in usedCapacity(): "right
        // now" is exactly the question of real-time safety this design
        // requires not to depend on the periodic sweep having already run.
        $bookingUsage = $this->rejectPastGrace($overlappingNow, fn (Booking $b) => $room->isShared())
            ->sum('party_size');

        $sessionUsage = (int) $room->openSharedSessions()->sum('party_size');

        // Open Session (exclusive rooms only — shared rooms use the open
        // SharedSession above for exactly this). Existence, not a sum: an
        // exclusive room's effectiveCapacity() is always 1.
        $openBookingUsage = (! $room->isShared() && $room->bookings()->where('status', 'open')->exists()) ? 1 : 0;

        return (int) $bookingUsage + $sessionUsage + $openBookingUsage;
    }

    /**
     * Bulk counterpart to usedCapacityNow(), for EXCLUSIVE rooms only — never
     * pass shared-room ids, their live occupancy is the existing
     * withSum('sharedSessions') idiom used elsewhere (ActiveSessionController,
     * SharedSessionController). One grouped query instead of N across a room
     * list. Mirrors usedCapacityNow()'s "overlapping right now" predicate
     * (today, status pending/confirmed, start_time <= now < end_time) and
     * intentionally omits its isPastNoShowGrace() reject, which only ever
     * applies when $room->isShared() — never true for the exclusive rooms
     * this is for. Keep this in sync if usedCapacityNow()'s predicate ever
     * changes.
     *
     * @param  int[]  $exclusiveRoomIds
     * @return array<int, int> room_id => seats occupied by a booking in progress right now
     */
    public function usedCapacityNowBulk(array $exclusiveRoomIds, int $ownerId): array
    {
        if ($exclusiveRoomIds === []) {
            return [];
        }

        $now = Carbon::now();

        $usage = Booking::where('owner_id', $ownerId)
            ->whereIn('room_id', $exclusiveRoomIds)
            ->whereDate('booking_date', $now->format('Y-m-d'))
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('start_time', '<=', $now->format('H:i:s'))
            ->where('end_time', '>', $now->format('H:i:s'))
            ->selectRaw('room_id, SUM(party_size) as used_seats')
            ->groupBy('room_id')
            ->pluck('used_seats', 'room_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        // Open Session bookings have no end_time to compare against "now" —
        // they always occupy from the moment they're created, so existence
        // (not a time-window match) is what counts them here.
        $openRoomIds = Booking::where('owner_id', $ownerId)
            ->whereIn('room_id', $exclusiveRoomIds)
            ->where('status', 'open')
            ->pluck('room_id');

        foreach ($openRoomIds as $roomId) {
            $usage[$roomId] = ($usage[$roomId] ?? 0) + 1;
        }

        return $usage;
    }

    /** Seats free right now (see usedCapacityNow()). */
    public function availableNow(Room $room, ?int $excludeBookingId = null): int
    {
        return max(0, $room->effectiveCapacity() - $this->usedCapacityNow($room, $excludeBookingId));
    }

    /**
     * Shared-room-only: seats occupied right now by currently-open
     * SharedSessions. This is not new logic — it's Room::availableSharedSlots()'s
     * existing query, exposed through this service for a consistent API
     * surface. Deliberately kept separate from availabilityForRange()/
     * freeBusyForDay(): those answer "is there room to schedule a Booking,"
     * this answers "who's actually here right now" — two different data
     * sources (Booking vs. open SharedSession) that this service composes
     * for reads without merging their underlying write paths.
     */
    public function liveOccupancy(Room $room): array
    {
        $capacity = $room->effectiveCapacity();
        $occupied = $room->isShared() ? (int) $room->openSharedSessions()->sum('party_size') : 0;

        return [
            'capacity' => $capacity,
            'occupied' => $occupied,
            'available' => max(0, $capacity - $occupied),
        ];
    }
}
