<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Owner;
use App\Models\Room;
use Illuminate\Support\Collection;

class BookingAnalyticsService
{
    /** Mirrors the match arms in Booking::statusLabel()/statusColor() — 'open' (Open Session) included, previously missing here, which silently dropped those bookings from statusBreakdown(). */
    private const STATUSES = ['pending', 'confirmed', 'checked_in', 'open', 'completed', 'cancelled', 'no_show'];

    /** A single day+hour cell needs at least this many bookings before it's confident enough to call "your busiest time." */
    public const MIN_BUSIEST_CELL_COUNT = 3;

    /** Total bookings in the period, optionally excluding cancelled — matches the existing "today's bookings" definition. */
    public function bookingsCount(Owner $owner, AnalyticsPeriod $period, bool $excludeCancelled = false): int
    {
        $query = Booking::where('owner_id', $owner->id)
            ->whereDate('booking_date', '>=', $period->startDate())
            ->whereDate('booking_date', '<=', $period->endDate());

        if ($excludeCancelled) {
            $query->where('status', '!=', 'cancelled');
        }

        return $query->count();
    }

    /** Booking count per status for the period, zero-filled for every known status so a chart never needs to guess. */
    public function statusBreakdown(Owner $owner, AnalyticsPeriod $period): array
    {
        $counts = Booking::where('owner_id', $owner->id)
            ->whereDate('booking_date', '>=', $period->startDate())
            ->whereDate('booking_date', '<=', $period->endDate())
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return collect(self::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    public function countByStatus(Owner $owner, AnalyticsPeriod $period, string $status): int
    {
        return $this->statusBreakdown($owner, $period)[$status] ?? 0;
    }

    /**
     * Per-room hours booked / revenue / booking count for the period, plus a
     * utilization % when the owner has configured working hours (booked
     * hours ÷ total open hours across the period). When hours aren't
     * configured, utilization_percent is null rather than computed against
     * a guessed denominator — callers should fall back to ranking by
     * hours_booked instead. One grouped query across all rooms, never a
     * per-room loop; the working-hours total is one pass over the period's
     * dates (bounded by the period length), not by room count.
     *
     * @return Collection<int, array{room_id:int, room_name:string, hours_booked:float, revenue:float, bookings_count:int, utilization_percent: ?float}>
     */
    public function roomUtilization(Owner $owner, AnalyticsPeriod $period, BusinessHoursService $businessHours): Collection
    {
        $perRoom = Booking::where('owner_id', $owner->id)
            ->whereIn('status', ['completed', 'checked_in', 'confirmed'])
            ->whereDate('booking_date', '>=', $period->startDate())
            ->whereDate('booking_date', '<=', $period->endDate())
            ->selectRaw('room_id, SUM(total_hours) as hours, SUM(total_price - discount_total) as revenue, COUNT(*) as bookings')
            ->groupBy('room_id')
            ->get()
            ->keyBy('room_id');

        $rooms = Room::where('owner_id', $owner->id)->get(['id', 'name', 'type']);

        $openHoursInPeriod = $businessHours->hasConfiguredHours($owner)
            ? $this->openHoursAcrossPeriod($owner, $period, $businessHours)
            : null;

        return $rooms->map(function (Room $room) use ($perRoom, $openHoursInPeriod) {
            $row = $perRoom->get($room->id);
            $hours = $row ? round((float) $row->hours, 2) : 0.0;

            return [
                'room_id' => $room->id,
                // The model itself, so views can reuse Room::typeLabel()/typeColor()
                // instead of re-deriving a type => color mapping locally.
                'room' => $room,
                'room_name' => $room->name,
                'hours_booked' => $hours,
                'revenue' => $row ? round((float) $row->revenue, 2) : 0.0,
                'bookings_count' => $row ? (int) $row->bookings : 0,
                'utilization_percent' => ($openHoursInPeriod !== null && $openHoursInPeriod > 0)
                    ? round(min(100, ($hours / $openHoursInPeriod) * 100), 1)
                    : null,
            ];
        })->values();
    }

    /**
     * Convenience wrappers around roomUtilization() for the single
     * highest/lowest room. Each call recomputes the full per-room
     * aggregate, so a caller that needs both plus the full table should
     * call roomUtilization() once directly and derive first()/last() from
     * its (already utilization-sorted-by-caller) result instead of calling
     * both of these — they're for call sites that only need the one figure.
     */
    public function mostUtilizedRoom(Owner $owner, AnalyticsPeriod $period, BusinessHoursService $businessHours): ?array
    {
        return $this->rankRoomsDesc($owner, $period, $businessHours)->first();
    }

    public function leastUtilizedRoom(Owner $owner, AnalyticsPeriod $period, BusinessHoursService $businessHours): ?array
    {
        return $this->rankRoomsDesc($owner, $period, $businessHours)->last();
    }

    private function rankRoomsDesc(Owner $owner, AnalyticsPeriod $period, BusinessHoursService $businessHours): Collection
    {
        return $this->roomUtilization($owner, $period, $businessHours)
            ->sortByDesc(fn (array $r) => $r['utilization_percent'] ?? $r['hours_booked'])
            ->values();
    }

    /**
     * Total open hours across every date in the period, from configured
     * working hours. Public so callers other than roomUtilization() (e.g.
     * a "revenue per available hour" figure) can share this one
     * owner-wide total instead of recomputing it.
     */
    public function openHoursAcrossPeriod(Owner $owner, AnalyticsPeriod $period, BusinessHoursService $businessHours): float
    {
        $minutes = 0;
        $cursor = $period->start->copy()->startOfDay();

        while ($cursor->lte($period->end)) {
            foreach ($businessHours->effectiveWindowForDate($owner, $cursor->toDateString()) as [$start, $end]) {
                $minutes += $this->minutesBetween($start, $end);
            }
            $cursor->addDay();
        }

        return round($minutes / 60, 2);
    }

    /** Same H:i(:s)/'24:00' sentinel handling as BusinessHoursService's own private toMinutes(). */
    private function minutesBetween(string $start, string $end): int
    {
        $toMinutes = function (string $time): int {
            if (str_starts_with($time, '24:00')) {
                return 24 * 60;
            }

            [$hours, $minutes] = array_map('intval', explode(':', $time));

            return ($hours * 60) + $minutes;
        };

        return max(0, $toMinutes($end) - $toMinutes($start));
    }

    /**
     * Booking count grouped by the hour their start_time falls in (0-23),
     * excluding cancelled bookings. One grouped query; only hours with at
     * least one booking are present in the result.
     *
     * @return array<int, int> hour => count
     */
    public function peakHours(Owner $owner, AnalyticsPeriod $period): array
    {
        return Booking::where('owner_id', $owner->id)
            ->where('status', '!=', 'cancelled')
            ->whereDate('booking_date', '>=', $period->startDate())
            ->whereDate('booking_date', '<=', $period->endDate())
            ->selectRaw('CAST(substr(start_time, 1, 2) AS INTEGER) as hour, COUNT(*) as c')
            ->groupBy('hour')
            ->pluck('c', 'hour')
            ->map(fn ($c) => (int) $c)
            ->sortKeys()
            ->all();
    }

    /**
     * Booking count grouped by day-of-week (0=Sunday..6=Saturday) and start
     * hour (0-23), excluding cancelled AND no-show bookings — unlike
     * peakHours() above (kept as-is for its existing single-dimension
     * callers), this also excludes no_show since a booking nobody showed up
     * for isn't evidence the business was actually busy then. Zero-filled
     * for every one of the 168 cells so the heatmap view never has to guess
     * a missing key.
     *
     * @return array<int, array<int, int>> dayOfWeek => [hour => count]
     */
    public function peakHoursByDayOfWeek(Owner $owner, AnalyticsPeriod $period): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        return Booking::where('owner_id', $owner->id)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereDate('booking_date', '>=', $period->startDate())
            ->whereDate('booking_date', '<=', $period->endDate())
            ->selectRaw("CAST(strftime('%w', booking_date) AS INTEGER) as dow, CAST(substr(start_time, 1, 2) AS INTEGER) as hour, COUNT(*) as c")
            ->groupBy('dow', 'hour')
            ->get()
            ->reduce(function (array $grid, $row) {
                $grid[(int) $row->dow][(int) $row->hour] = (int) $row->c;

                return $grid;
            }, $grid);
    }

    /**
     * Today's still-relevant bookings (not cancelled/no-show), ordered by
     * start time, with room/customer eager-loaded — a display listing for
     * the dashboard's "today's schedule," not an aggregate.
     */
    public function todaysSchedule(Owner $owner, int $limit = 20): Collection
    {
        return Booking::where('owner_id', $owner->id)
            ->whereDate('booking_date', today())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->with(['room:id,name,type', 'hotspotUser:id,name'])
            ->orderBy('start_time')
            ->limit($limit)
            ->get();
    }
}
