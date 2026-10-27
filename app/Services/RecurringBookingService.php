<?php

namespace App\Services;

use App\Exceptions\InsufficientPackageBalanceException;
use App\Exceptions\OccurrenceConflictException;
use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\Owner;
use App\Models\Room;
use App\Models\RoomPricingProfile;
use App\Support\Pricing\PriceQuote;
use App\Support\Recurrence\RecurrenceRule;
use App\Support\RequestIdempotency;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Recurring bookings. A series only holds the repeat rule; every occurrence
 * is an ordinary Booking created through the same rules as a one-time
 * booking (BookingController::store()): the physical-room lock
 * (Room::lockInventory()), AvailabilityService's capacity check plus its
 * post-write re-check, working hours, a switched-off room refused, and the
 * price from RoomPricingService::quoteBooking(). Each occurrence is its own
 * transaction, so one conflict never sinks the rest of the series — and a
 * conflict is reported, never moved or overwritten.
 *
 * "Draft" = what a series books: ['owner', 'room', 'member', 'profile',
 * 'rule' (RecurrenceRule), 'start', 'end', 'party_size', 'guest_count', 'notes',
 * 'package' (?MemberPackage the owner chose), 'payment_plan', 'price' (the
 * agreed snapshot of an existing series, else null)].
 *
 * Money: every date is a normal booking with its own price and payments
 * (bookings.amount_paid) — series totals are always summed from them
 * (financials()), so nothing is ever counted twice and a cancellation needs
 * no separate adjustment. Package hours are held per covered date with the
 * same HourPackageService calls as a one-time booking (whole dates only, while
 * the package lasts) and given back by the normal cancellation.
 */
class RecurringBookingService
{
    /** Why an occurrence can't be booked (keys of app.series.reasons). */
    public const PAST = 'past';

    public const BOOKED = 'booked';

    public const CLOSED = 'closed';

    public const ROOM_OFF = 'room_off';

    public const PRICING = 'pricing';

    public const PAID_MORE = 'paid_more';

    /** An occurrence with its own package / plan / coupon: never re-priced by a series change. */
    public const CUSTOM = 'custom';

    /** The series already has a (not cancelled) booking on that date. */
    public const EXISTS = 'exists';

    public const STOPPED = 'stopped';

    /** A database error while saving this one date (reported, the rest go on). */
    public const ERROR = 'error';

    /** The series' package can't cover the new length of a date it covers. */
    public const PACKAGE_SHORT = 'package_short';

    /** Once-only scope for a series-level payment (RequestIdempotency). */
    public const SCOPE_SERIES_PAYMENT = 'series.payment';

    /** How far ahead later batches are added (bookings:extend-series). */
    public const HORIZON_DAYS = 60;

    public function __construct(
        private AvailabilityService $availability,
        private BusinessHoursService $businessHours,
        private RoomPricingService $pricing,
        private BookingCancellationService $cancellation,
        private NotificationService $notifications,
        private ActivityLogger $activityLogger,
        private HourPackageService $packages,
    ) {}

    // ------------------------------------------------------------------ review

    /**
     * The first batch of the series as it would be booked now: each date
     * with its price, and — when it can't be booked — why, plus up to three
     * other times that day. Nothing is held: confirmation re-checks
     * everything under the room lock.
     *
     * With a chosen package: which bookable dates it would cover (whole
     * dates, in date order, while its balance lasts — coverage()), and the
     * cash total for the rest.
     *
     * @return array{occurrences: array<int, array>, more: bool, total: float, minutes: int, package: ?array}
     */
    public function preview(array $draft): array
    {
        $dates = $draft['rule']->dates(null, RecurrenceRule::BATCH);
        $minutes = $this->lengthOf($draft['start'], $draft['end']);
        $occurrences = [];

        foreach ($dates as $date) {
            $reason = $this->checkSlot($draft, $date, $draft['start'], $draft['end']);
            $price = $this->priceFor($draft, $date, $draft['start'], $draft['end']);
            if ($reason === null && $price === null) {
                $reason = self::PRICING;
            }
            $occurrences[] = [
                'date' => $date,
                'start' => $draft['start'],
                'end' => $draft['end'],
                'minutes' => $minutes,
                'reason' => $reason,
                'price' => $price,
                // The chosen package may cover this date (its terms; balance aside).
                'pkg_ok' => $this->packageFits($draft, $date, $minutes),
                'suggestions' => $reason !== null && $reason !== self::PAST && $reason !== self::ROOM_OFF
                    ? $this->suggestions($draft, $date, $draft['start'], $draft['end'])
                    : [],
            ];
        }

        $covered = $this->coverage($draft, collect($occurrences)->whereNull('reason')->all());
        $total = 0.0;
        $bookedMinutes = 0;
        foreach ($occurrences as $i => $o) {
            $occurrences[$i]['covered'] = isset($covered[$o['date']]);
            if ($o['reason'] === null) {
                $bookedMinutes += $o['minutes'];
                $total += $occurrences[$i]['covered'] ? 0 : $o['price'];
            }
        }

        return [
            'occurrences' => $occurrences,
            'more' => $dates !== [] && $draft['rule']->hasDatesAfter(end($dates)),
            'total' => round($total, 2),
            'minutes' => $bookedMinutes,
            'package' => $draft['package'] ? [
                'id' => $draft['package']->id,
                'name' => $draft['package']->name,
                'remaining_minutes' => $this->packages->available($draft['package']),
                'covered_minutes' => count($covered) * $minutes,
                'covered_count' => count($covered),
            ] : null,
        ];
    }

    /**
     * Which of $occurrences (date order) the draft's package covers: whole
     * dates only, each one the package's terms allow, while its balance lasts.
     * A date it can't fit is charged at the normal price; what's left stays on
     * the package. Never applied without the owner choosing the package.
     *
     * @return array<string, true> date => covered
     */
    public function coverage(array $draft, array $occurrences): array
    {
        if (! $draft['package']) {
            return [];
        }
        $left = $this->packages->available($draft['package']);
        $covered = [];
        foreach ($occurrences as $o) {
            if (($o['pkg_ok'] ?? false) && $o['minutes'] <= $left) {
                $covered[$o['date']] = true;
                $left -= $o['minutes'];
            }
        }

        return $covered;
    }

    /** The package's terms allow this date (dates, rooms, not shared, not cancelled) — balance aside. */
    private function packageFits(array $draft, string $date, int $minutes): bool
    {
        if (! $draft['package'] || $draft['room']->isShared()) {
            return false;
        }
        $reason = $this->packages->eligibility($draft['package'], $draft['room'], $date, $minutes, (int) $draft['party_size']);

        return $reason === null || $reason === 'insufficient';
    }

    private function lengthOf(string $start, string $end): int
    {
        $toMin = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);

        return $toMin($end) - $toMin($start);
    }

    // ------------------------------------------------------------------ create

    /**
     * Create the series and book the chosen occurrences, each re-checked
     * under the lock. $choices: [['date' => Y-m-d, 'start' => H:i, 'end' => H:i], …]
     * — only dates of the reviewed batch (the caller validates that).
     *
     * @return array{series: ?BookingSeries, created: Booking[], failed: array<int, array>} series is null when no date could be booked (nothing is kept)
     */
    public function create(array $draft, array $choices, string $requestToken, array $reviewedCovered = []): array
    {
        $batch = $draft['rule']->dates(null, RecurrenceRule::BATCH);
        // The agreed price: what one date costs now. Every date — this batch
        // and later ones — is booked at it until the owner changes it.
        $agreed = $this->quote($draft, $draft['rule']->startsOn->toDateString(), $draft['start'], $draft['end']);

        $series = BookingSeries::create([
            'owner_id' => $draft['owner']->id,
            'room_id' => $draft['room']->id,
            'hotspot_user_id' => $draft['member']->id,
            'room_pricing_profile_id' => $draft['profile']?->id,
            'frequency' => $draft['rule']->frequency,
            'weekdays' => $draft['rule']->frequency === RecurrenceRule::WEEKLY ? $draft['rule']->weekdays : null,
            'starts_on' => $draft['rule']->startsOn->toDateString(),
            'ends_on' => $draft['rule']->endsOn?->toDateString(),
            'occurrence_limit' => $draft['rule']->count,
            'start_time' => $draft['start'],
            'end_time' => $draft['end'],
            'party_size' => $draft['party_size'],
            'guest_count' => $draft['guest_count'],
            'notes' => $draft['notes'],
            'price_per_hour' => $agreed?->ratePerHour,
            'occurrence_price' => $agreed?->totalPrice,
            'pricing_note' => $agreed?->note,
            'payment_plan' => $draft['payment_plan'] ?? BookingSeries::PLAN_PER_SESSION,
            'member_package_id' => $draft['package']?->id,
            'status' => BookingSeries::ACTIVE,
            'generated_through' => $batch === [] ? null : end($batch),
            'request_token' => $requestToken,
            // Every chosen date starts out as "not booked yet": if this request
            // dies half-way, the dates it never reached stay listed on the
            // series for the owner instead of being lost (the series page
            // hides the ones that did get booked).
            'unresolved' => collect($choices)->map(fn ($c) => ['date' => $c['date'], 'start' => $c['start'], 'end' => $c['end'], 'reason' => self::ERROR])
                ->sortBy('date')->values()->all() ?: null,
        ]);

        usort($choices, fn ($a, $b) => [$a['date'], $a['start']] <=> [$b['date'], $b['start']]);
        // The dates the owner saw as covered by the package on the review: any
        // of them it can no longer cover (its hours went elsewhere meanwhile)
        // is reported — the money never changes silently.
        $planned = array_flip($reviewedCovered);
        [$created, $failed] = $this->bookMany($series, $choices);

        // Not one date could be booked (taken since the review, or a forged
        // confirm): keep nothing — an empty active series would only collect
        // later batches (bookings:extend-series) and announce itself as created.
        if ($created === []) {
            $series->delete();

            return ['series' => null, 'created' => [], 'failed' => $failed, 'uncovered' => 0];
        }

        $this->rememberUnresolved($series, $failed, array_column($choices, 'date'));

        $this->activityLogger->log('booking.series_created', $series,
            "Repeating booking: {$draft['room']->name} for {$draft['member']->name}, ".count($created).' booked, '.count($failed).' not booked'
            .', '.($series->isUpfront() ? 'paid upfront' : 'paid per date').($draft['package'] ? ", package \"{$draft['package']->name}\"" : ''));
        $this->notifications->notify($draft['owner'], [
            'type' => 'series_created',
            'level' => $failed ? 'warning' : 'info',
            'reference' => "series_created:{$series->id}",
            'title' => __('app.series.notif.created_title', ['room' => $draft['room']->name]),
            'body' => __('app.series.notif.created_body', ['booked' => count($created), 'failed' => count($failed), 'customer' => $draft['member']->name]),
            'action_url' => route('bookings.series.show', $series),
        ]);

        $uncovered = collect($created)->filter(fn (Booking $b) => isset($planned[$b->booking_date->toDateString()]) && ! $b->isPackageCovered())->count();

        return ['series' => $series, 'created' => $created, 'failed' => $failed, 'uncovered' => $uncovered];
    }

    /**
     * Book one date of a series (also used to resolve a conflict with
     * another time). Returns [Booking|null, reason|null]. The date is
     * priced at the series' agreed price (seriesQuote()) and, when the
     * series has a package that still fits, covered by it.
     */
    public function bookOccurrence(BookingSeries $series, string $date, string $start, string $end): array
    {
        $draft = $this->draftOf($series);
        if ($reason = $this->pastReason($date, $start)) {
            return [null, $reason];
        }

        try {
            $booking = DB::transaction(function () use ($series, $draft, $date, $start, $end) {
                // Lock first (only locking reads before it — Room::lockInventory()).
                $room = Room::lockInventory($series->room_id);
                // Under the lock, so a stop and a retry (or two overlapping
                // extend runs) can't both add the same date: a stopped series
                // takes no new dates, and a date is booked once per series.
                if (BookingSeries::whereKey($series->id)->lockForUpdate()->value('status') !== BookingSeries::ACTIVE) {
                    return self::STOPPED;
                }
                if (Booking::where('booking_series_id', $series->id)->whereDate('booking_date', $date)->where('status', '!=', 'cancelled')->exists()) {
                    return self::EXISTS;
                }
                if (! $room->is_available) {
                    return self::ROOM_OFF;
                }
                if (! $this->businessHours->isWithinWorkingHours($draft['owner'], $date, $start, $end)) {
                    return self::CLOSED;
                }
                if ($this->availability->availabilityForRange($room, $date, $start, $end) < $this->seats($room, $draft)) {
                    return self::BOOKED;
                }
                $quote = $this->seriesQuote(array_merge($draft, ['room' => $room]), $date, $start, $end);
                if ($quote === null) {
                    return self::PRICING;
                }

                $booking = new Booking([
                    'owner_id' => $series->owner_id,
                    'room_id' => $room->id,
                    'room_pricing_profile_id' => $draft['profile']?->id,
                    'pricing_profile_name' => $draft['profile']?->name,
                    'hotspot_user_id' => $series->hotspot_user_id,
                    'party_size' => $draft['party_size'],
                    'guest_count' => $room->isShared() ? null : $draft['guest_count'],
                    'booking_date' => $date,
                    'start_time' => $start,
                    'end_time' => $end,
                    'price_per_hour' => $quote->ratePerHour,
                    'total_hours' => $quote->totalHours(),
                    'total_price' => $quote->totalPrice,
                    'pricing_note' => $quote->note,
                    // No upfront payment for a series: each occurrence starts
                    // unpaid and is paid like any booking (recordPayment()).
                    'amount_paid' => 0,
                    'payment_status' => Booking::derivePaymentStatus(0, $quote->totalPrice),
                    'status' => 'confirmed',
                    'notes' => $series->notes,
                ]);
                $booking->booking_series_id = $series->id;
                $booking->save();

                // Post-write re-check, as in store(): a concurrent write that
                // slipped past the lock rolls this occurrence back.
                if ($this->availability->exceedsCapacity($room, $date, $start, $end)) {
                    throw new OccurrenceConflictException;
                }

                // The series' package, while it still fits this whole date:
                // held now with the same guarded write as a one-time booking
                // (never twice — the claim fails when the balance is gone).
                // Doesn't fit → the date stays at its normal price.
                $minutes = (int) ceil($quote->totalMinutes);
                if ($draft['package'] && $this->packageFits(array_merge($draft, ['room' => $room]), $date, $minutes)) {
                    try {
                        $this->packages->coverBooking($booking, $draft['package'], $minutes);
                    } catch (InsufficientPackageBalanceException) {
                        // Nothing was written: the normal price stands.
                    }
                }

                return $booking;
            }, 3);
        } catch (OccurrenceConflictException) {
            return [null, self::BOOKED];
        }

        if (! $booking instanceof Booking) {
            return [null, $booking];
        }
        // Same audit entry as a one-time booking (a no-op for the scheduled extension: nobody signed in).
        $this->activityLogger->log('booking.created', $booking, "Booked {$draft['room']->name} for {$booking->booking_date->format('M d, Y')} (repeating booking #{$series->id})");

        return [$booking, null];
    }

    // ------------------------------------------------------------------ manage

    /**
     * Change the time / party of this occurrence and every later one still
     * pending or confirmed, each re-checked (its own old time excluded).
     * Ones that can't move keep their current time and are reported. Past
     * occurrences are never touched; the series' time changes for dates
     * added later.
     *
     * Price: a time shift (same length, same party) keeps the agreed price.
     * A new length or party is a new deal — re-quoted at the room's current
     * pricing, and that becomes the series' agreed price. Dates the series'
     * package covers move with their hours reconciled (as BookingController::
     * update() does); dates changed on their own (another package, a plan, a
     * coupon) are left alone and reported.
     *
     * @return array{updated: Booking[], failed: array<int, array>}
     */
    public function updateFuture(BookingSeries $series, Booking $from, string $start, string $end, int $partySize, ?int $guestCount): array
    {
        $attributes = ['start_time' => $start, 'end_time' => $end, 'party_size' => $partySize, 'guest_count' => $guestCount];
        $samePrice = $this->lengthOf($start, $end) === $series->lengthMinutes()
            && $partySize === (int) $series->party_size && $guestCount === $series->guest_count;
        if (! $samePrice) {
            $requote = $this->quote(array_merge($this->draftOf($series), $attributes, ['start' => $start, 'end' => $end]),
                max($from->booking_date->toDateString(), today()->toDateString()), $start, $end);
            $attributes += ['price_per_hour' => $requote?->ratePerHour, 'occurrence_price' => $requote?->totalPrice, 'pricing_note' => $requote?->note];
        }
        $series->update($attributes);
        $draft = $this->draftOf($series->fresh());
        $updated = [];
        $failed = [];

        foreach ($this->futureOccurrences($series, $from) as $booking) {
            $date = $booking->booking_date->toDateString();
            if ($reason = $this->pastReason($date, $start)) {
                $failed[] = ['date' => $date, 'start' => $start, 'end' => $end, 'reason' => $reason, 'booking_id' => $booking->id];

                continue;
            }

            try {
                $result = DB::transaction(function () use ($series, $booking, $draft, $date, $start, $end, $partySize, $guestCount) {
                    $room = Room::lockInventory($booking->room_id);
                    $locked = Booking::whereKey($booking->id)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->first();
                    if (! $locked) {
                        return null; // cancelled/checked in meanwhile — leave it
                    }
                    // Changed on its own since (BookingController::update()):
                    // another package, a plan's window or a coupon's discount need
                    // that flow's reconciliation — never re-priced from here.
                    $seriesPackage = $series->member_package_id !== null && (int) $locked->member_package_id === (int) $series->member_package_id;
                    if (($locked->member_package_id !== null && ! $seriesPackage) || $locked->room_plan_id !== null || $locked->coupon_id !== null) {
                        return self::CUSTOM;
                    }
                    if (! $this->businessHours->isWithinWorkingHours($draft['owner'], $date, $start, $end)) {
                        return self::CLOSED;
                    }
                    if ($this->availability->availabilityForRange($room, $date, $start, $end, excludeBookingId: $locked->id) < $this->seats($room, $draft)) {
                        return self::BOOKED;
                    }
                    $quote = $this->seriesQuote(array_merge($draft, ['room' => $room]), $date, $start, $end);
                    if ($quote === null) {
                        return self::PRICING;
                    }
                    // Money already taken is never silently over the new price.
                    if (! $seriesPackage && (float) $locked->amount_paid > $quote->totalPrice) {
                        return self::PAID_MORE;
                    }

                    $locked->update([
                        'start_time' => $start, 'end_time' => $end, 'party_size' => $partySize,
                        'guest_count' => $room->isShared() ? null : $guestCount,
                        'price_per_hour' => $quote->ratePerHour, 'total_hours' => $quote->totalHours(),
                        'total_price' => $quote->totalPrice, 'pricing_note' => $quote->note,
                        'payment_status' => Booking::derivePaymentStatus((float) $locked->amount_paid, $quote->totalPrice),
                    ]);

                    if ($this->availability->exceedsCapacity($room, $date, $start, $end)) {
                        throw new OccurrenceConflictException;
                    }
                    // Still covered: hold exactly the new length (draws or returns
                    // the difference); not enough left → the whole change for this
                    // date rolls back (InsufficientPackageBalanceException).
                    if ($seriesPackage) {
                        $this->packages->coverBooking($locked, $draft['package'], (int) ceil($quote->totalMinutes));
                    }

                    return $locked;
                }, 3);
            } catch (OccurrenceConflictException) {
                $result = self::BOOKED;
            } catch (InsufficientPackageBalanceException) {
                $result = self::PACKAGE_SHORT;
            }

            if ($result instanceof Booking) {
                $updated[] = $result;
                $this->activityLogger->log('booking.updated', $result, "Updated booking #{$result->id} (repeating booking #{$series->id})");
            } elseif (is_string($result)) {
                $failed[] = ['date' => $date, 'start' => $start, 'end' => $end, 'reason' => $result, 'booking_id' => $booking->id];
            }
        }

        $this->activityLogger->log('booking.series_updated', $series,
            "Repeating booking from {$from->booking_date->toDateString()}: {$start}–{$end}, ".count($updated).' moved, '.count($failed).' kept');

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * Cancel this occurrence and every later one still pending or confirmed
     * (the normal cancellation, one by one), and stop the series. Past and
     * already-completed occurrences stay exactly as they are.
     */
    public function cancelFuture(BookingSeries $series, Booking $from): int
    {
        $cancelled = 0;
        foreach ($this->futureOccurrences($series, $from) as $booking) {
            if ($this->cancellation->cancel($booking)) {
                $cancelled++;
                $this->activityLogger->log('booking.cancelled', $booking, "Booking #{$booking->id} status changed to Cancelled (repeating booking #{$series->id})");
            }
        }

        $fromDate = $from->booking_date->toDateString();
        $this->halt($series, $fromDate);
        $this->activityLogger->log('booking.series_cancelled', $series, "Repeating booking cancelled from {$fromDate}: {$cancelled} bookings");

        return $cancelled;
    }

    // ------------------------------------------------------------------ money

    /**
     * The series' money, always summed from its dates (so it can never drift
     * or count a payment twice, and every cancellation is reflected at once):
     *
     *  - total:     what the active dates cost (cancelled ones drop out)
     *  - paid:      everything actually recorded, cancelled dates included
     *  - remaining: what's still due on the active dates
     *  - overpaid:  money on cancelled dates (or above a date's price) — never
     *               refunded or credited automatically: the owner reconciles it
     *
     * so total − paid = remaining − overpaid. Dates covered by the package are
     * counted in hours, not money: reserved (upcoming) and used (completed /
     * checked in).
     *
     * @param  iterable<Booking>  $bookings  the series' dates
     */
    public function financials(BookingSeries $series, iterable $bookings): array
    {
        $out = ['total' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'overpaid' => 0.0, 'dates' => 0, 'cancelled' => 0,
            'unpaid_dates' => 0, 'package_reserved_minutes' => 0, 'package_used_minutes' => 0, 'package_dates' => 0];

        foreach ($bookings as $b) {
            $cancelled = $b->status === 'cancelled';
            $cancelled ? $out['cancelled']++ : $out['dates']++;
            if ($b->isPackageCovered()) {
                if (! $cancelled) {
                    $out['package_dates']++;
                    $minutes = (int) round((float) $b->total_hours * 60);
                    in_array($b->status, ['completed', 'checked_in'], true)
                        ? $out['package_used_minutes'] += $minutes
                        : $out['package_reserved_minutes'] += $minutes;
                }

                continue;
            }

            $paid = (float) $b->amount_paid;
            $out['paid'] += $paid;
            if ($cancelled) {
                $out['overpaid'] += $paid;

                continue;
            }
            $out['total'] += $b->netRoomCharge();
            $out['remaining'] += $b->balanceDue();
            $out['overpaid'] += max(0, $paid - $b->netRoomCharge());
            if ($b->payment_status !== Booking::PAYMENT_PAID) {
                $out['unpaid_dates']++;
            }
        }

        foreach (['total', 'paid', 'remaining', 'overpaid'] as $k) {
            $out[$k] = round($out[$k], 2);
        }

        return $out;
    }

    /**
     * A payment for the series as a whole (upfront plan): recorded on its
     * dates, earliest first, each through the same guarded write as a payment
     * on one booking (Booking::applyPayment()) — so the series balance is
     * just those dates' balances, never a second copy of the money. Once-only
     * per $key (a double click records it once). Refused (nothing written)
     * when it is more than what's still due.
     *
     * @return array{status: 'recorded'|'replayed'|'exceeds', applied: array<int, float>, remaining: float}
     */
    public function recordSeriesPayment(BookingSeries $series, float $amount, ?string $key): array
    {
        $amount = round($amount, 2);
        $hash = $key ? RequestIdempotency::fingerprint(['series_id' => (int) $series->id, 'amount' => number_format($amount, 2, '.', '')]) : null;
        if ($key && ($claim = RequestIdempotency::find($series->owner_id, self::SCOPE_SERIES_PAYMENT, $key))) {
            return ['status' => $claim->request_hash === $hash ? 'replayed' : 'conflict', 'applied' => [], 'remaining' => $this->remaining($series)];
        }

        try {
            $applied = DB::transaction(function () use ($series, $amount, $key, $hash) {
                if ($key) {
                    RequestIdempotency::claim($series->owner_id, self::SCOPE_SERIES_PAYMENT, $key, $hash);
                }
                $dates = Booking::where('booking_series_id', $series->id)->where('owner_id', $series->owner_id)
                    ->whereNotIn('status', ['cancelled', 'no_show'])
                    ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', '!=', Booking::METHOD_PACKAGE))
                    ->orderBy('booking_date')->orderBy('start_time')
                    ->lockForUpdate()->get();

                $left = $amount;
                $applied = [];
                foreach ($dates as $b) {
                    $part = round(min($left, $b->balanceDue()), 2);
                    if ($part <= 0) {
                        continue;
                    }
                    if (! Booking::applyPayment($b->id, $part)) {
                        throw new \LengthException; // the date changed under the lock: roll everything back
                    }
                    $applied[$b->id] = $part;
                    $left = round($left - $part, 2);
                    if ($left <= 0) {
                        break;
                    }
                }
                if ($left > 0.004) {
                    throw new \LengthException; // more than is due: roll everything back
                }

                return $applied;
            }, 3);
        } catch (\LengthException) {
            return ['status' => 'exceeds', 'applied' => [], 'remaining' => $this->remaining($series)];
        } catch (UniqueConstraintViolationException $e) {
            // Lost the race to an identical request: it has committed by now.
            $claim = $key ? RequestIdempotency::find($series->owner_id, self::SCOPE_SERIES_PAYMENT, $key) : null;
            if (! $claim) {
                throw $e;
            }

            return ['status' => $claim->request_hash === $hash ? 'replayed' : 'conflict', 'applied' => [], 'remaining' => $this->remaining($series)];
        }

        foreach ($applied as $id => $part) {
            $this->activityLogger->log('booking.payment_recorded', Booking::find($id), "Recorded a payment of {$part} for booking #{$id} (repeating booking #{$series->id})");
        }
        $this->activityLogger->log('booking.series_payment', $series, "Recorded a payment of {$amount} for repeating booking #{$series->id} across ".count($applied).' dates');

        return ['status' => 'recorded', 'applied' => $applied, 'remaining' => $this->remaining($series)];
    }

    private function remaining(BookingSeries $series): float
    {
        return $this->financials($series, $series->bookings()->get())['remaining'];
    }

    /**
     * What changing the agreed price per date to $newPrice would do, before
     * anything is saved: the upcoming dates it applies to (pending/confirmed,
     * not started, paid normally, not changed on their own), the ones it
     * can't (with why) and the series money before → after.
     */
    public function pricePreview(BookingSeries $series, float $newPrice): array
    {
        $newPrice = round($newPrice, 2);
        $bookings = $series->bookings()->get();
        $affected = [];
        $skipped = [];
        $diff = 0.0;

        $packageDates = 0;
        foreach ($bookings as $b) {
            if (! in_array($b->status, ['pending', 'confirmed'], true) || $this->pastReason($b->booking_date->toDateString(), substr((string) $b->start_time, 0, 5))) {
                continue; // history is never repriced
            }
            if ($this->coveredBySeriesPackage($series, $b)) {
                $packageDates++; // not charged money: a price doesn't apply

                continue;
            }
            if ($reason = $this->priceBlocker($b, $newPrice)) {
                $skipped[] = ['date' => $b->booking_date->toDateString(), 'reason' => $reason];

                continue;
            }
            $affected[] = $b;
            $diff += $newPrice - (float) $b->total_price;
        }

        $before = $this->financials($series, $bookings);
        $after = $before;
        $after['total'] = round($before['total'] + $diff, 2);
        // A repriced date never holds more money than its new price
        // (priceBlocker()), so its balance moves by exactly the difference.
        $after['remaining'] = round($before['remaining'] + $diff, 2);

        return [
            'old' => $series->occurrence_price !== null ? (float) $series->occurrence_price : null,
            'new' => $newPrice,
            'affected' => count($affected),
            'diff' => round($diff, 2),
            'skipped' => $skipped,
            'package_dates' => $packageDates,
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * Apply a new agreed price per date (after the owner saw pricePreview()):
     * upcoming dates that can take it are repriced under their row lock, the
     * series keeps it for dates added later. Past dates and every recorded
     * payment are untouched. Idempotent: a date already at the price is left
     * as it is.
     *
     * @return array{updated: int, skipped: array<int, array>}
     */
    public function changePrice(BookingSeries $series, float $newPrice): array
    {
        $newPrice = round($newPrice, 2);
        $old = $series->occurrence_price;
        $hours = max(0.01, $series->lengthMinutes() / 60);
        $note = __('app.series.agreed_price_note');
        $updated = 0;
        $skipped = [];

        foreach ($series->bookings()->whereIn('status', ['pending', 'confirmed'])->get() as $b) {
            if ($this->pastReason($b->booking_date->toDateString(), substr((string) $b->start_time, 0, 5)) || $this->coveredBySeriesPackage($series, $b)) {
                continue;
            }
            $result = DB::transaction(function () use ($b, $newPrice, $note) {
                $locked = Booking::whereKey($b->id)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->first();
                if (! $locked) {
                    return null;
                }
                if ($reason = $this->priceBlocker($locked, $newPrice)) {
                    return $reason;
                }
                if (round((float) $locked->total_price, 2) === $newPrice) {
                    return null; // already at it (a repeated request)
                }
                $lockedHours = max(0.01, (float) $locked->total_hours);
                $locked->update([
                    'total_price' => $newPrice,
                    'price_per_hour' => round($newPrice / $lockedHours, 2),
                    'pricing_note' => $note,
                    'payment_status' => Booking::derivePaymentStatus((float) $locked->amount_paid, max(0, $newPrice - (float) $locked->discount_total)),
                ]);

                return $locked;
            }, 3);

            if ($result instanceof Booking) {
                $updated++;
                $this->activityLogger->log('booking.updated', $result, "Updated booking #{$result->id}: price {$newPrice} (repeating booking #{$series->id})");
            } elseif (is_string($result)) {
                $skipped[] = ['date' => $b->booking_date->toDateString(), 'reason' => $result];
            }
        }

        $series->update(['occurrence_price' => $newPrice, 'price_per_hour' => round($newPrice / $hours, 2), 'pricing_note' => $note]);
        $this->activityLogger->log('booking.series_price_changed', $series,
            "Repeating booking #{$series->id}: price per date ".($old === null ? '—' : number_format((float) $old, 2))." → {$newPrice}, {$updated} dates repriced, ".count($skipped).' kept');

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /** A date the series' own package pays for (hours, not money). */
    private function coveredBySeriesPackage(BookingSeries $series, Booking $b): bool
    {
        return $b->isPackageCovered() && $series->member_package_id !== null && (int) $b->member_package_id === (int) $series->member_package_id;
    }

    /** Why $b keeps its own price under a series price change, or null. */
    private function priceBlocker(Booking $b, float $newPrice): ?string
    {
        if ($b->isPackageCovered() || $b->room_plan_id !== null || $b->coupon_id !== null) {
            return self::CUSTOM;
        }
        if ((float) $b->amount_paid > $newPrice + 0.004) {
            return self::PAID_MORE;
        }

        return null;
    }

    /** Stop adding dates. Bookings already made — paid or not — stay as they are. */
    public function stop(BookingSeries $series): void
    {
        $this->halt($series, today()->toDateString());
        $this->activityLogger->log('booking.series_stopped', $series, 'Repeating booking stopped');
    }

    /**
     * Add the next batch of dates for an active series whose booked dates
     * run out within HORIZON_DAYS (scheduled: bookings:extend-series). Each
     * date is booked or, when it can't be, reported — one summary
     * notification per batch.
     *
     * @return array{created: int, failed: int}
     */
    public function extend(BookingSeries $series): array
    {
        // The command's chunk may be minutes old: stopped or extended since?
        $series->refresh();
        if (! $series->isActive()) {
            return ['created' => 0, 'failed' => 0];
        }

        $horizon = today()->addDays(self::HORIZON_DAYS)->toDateString();
        // Dates after the last handled one — and never in the past.
        $after = max($series->generated_through?->toDateString() ?? '', today()->subDay()->toDateString());
        $dates = $series->rule()->dates($after, RecurrenceRule::BATCH, $horizon);
        if ($dates === []) {
            return ['created' => 0, 'failed' => 0];
        }

        $start = substr((string) $series->start_time, 0, 5);
        $end = substr((string) $series->end_time, 0, 5);
        [$created, $failed] = $this->bookMany($series, array_map(fn ($d) => ['date' => $d, 'start' => $start, 'end' => $end], $dates));
        // Only after the batch: a run that dies half-way is simply redone
        // (dates it already booked come back as EXISTS, never twice).
        $series->generated_through = end($dates);
        $series->save();
        $this->rememberUnresolved($series, $failed);

        $this->notifications->notify($series->owner, [
            'type' => 'series_extended',
            'level' => $failed ? 'warning' : 'info',
            'reference' => "series_extended:{$series->id}:".end($dates),
            'title' => __('app.series.notif.extended_title', ['room' => $series->room?->name ?? '—']),
            'body' => __('app.series.notif.extended_body', ['booked' => count($created), 'failed' => count($failed), 'customer' => $series->hotspotUser?->name ?? '—']),
            'action_url' => route('bookings.series.show', $series),
        ]);

        return ['created' => count($created), 'failed' => count($failed)];
    }

    /** Forget an unresolved date (the owner chose to skip it). */
    public function dismiss(BookingSeries $series, string $date): void
    {
        $this->editUnresolved($series, fn ($list) => $list->reject(fn ($u) => $u['date'] === $date));
    }

    /**
     * Remember dates that couldn't be booked, replacing any older entry for
     * the same date; $handled dates (just booked or retried) are cleared first.
     */
    public function rememberUnresolved(BookingSeries $series, array $failed, array $handled = []): void
    {
        if ($failed === [] && $handled === []) {
            return;
        }
        $this->editUnresolved($series, function ($list) use ($failed, $handled) {
            $byDate = $list->keyBy('date')->except($handled);
            foreach ($failed as $f) {
                $byDate[$f['date']] = ['date' => $f['date'], 'start' => $f['start'], 'end' => $f['end'], 'reason' => $f['reason']];
            }

            return $byDate->sortKeys();
        });
    }

    /** Stop the series; dates from $fromDate that never got booked are no longer offered. */
    private function halt(BookingSeries $series, string $fromDate): void
    {
        DB::transaction(function () use ($series, $fromDate) {
            $locked = BookingSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status' => BookingSeries::STOPPED,
                'stopped_at' => $locked->stopped_at ?? now(),
                'unresolved' => collect($locked->unresolved ?? [])->filter(fn ($u) => $u['date'] < $fromDate)->values()->all() ?: null,
            ]);
            $series->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Read-modify-write of the unresolved list under the series row lock, so
     * the scheduled extension and the owner (skip / book another time) never
     * overwrite each other's changes.
     */
    private function editUnresolved(BookingSeries $series, callable $change): void
    {
        DB::transaction(function () use ($series, $change) {
            $locked = BookingSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();
            $locked->update(['unresolved' => $change(collect($locked->unresolved ?? []))->values()->all() ?: null]);
            $series->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Upcoming occurrences that need attention because things changed after
     * they were booked: the room (or use) has since been switched off, or the
     * time is now outside working hours. Nothing is cancelled or moved —
     * this only says which ones, and why.
     *
     * @return array<int, string> booking id => reason
     */
    public function attention(BookingSeries $series, iterable $bookings): array
    {
        $owner = $series->owner;
        $out = [];
        // Working hours depend only on the weekday: one lookup per weekday +
        // time, however many dates the series has.
        $withinHours = [];
        foreach ($bookings as $b) {
            if (! in_array($b->status, ['pending', 'confirmed'], true) || $b->booking_date->lt(today())) {
                continue;
            }
            $start = substr((string) $b->start_time, 0, 5);
            $end = substr((string) $b->end_time, 0, 5);
            $key = $b->booking_date->dayOfWeek.'|'.$start.'|'.$end;
            $withinHours[$key] ??= $this->businessHours->isWithinWorkingHours($owner, $b->booking_date->toDateString(), $start, $end);
            if (! $b->room?->is_available) {
                $out[$b->id] = self::ROOM_OFF;
            } elseif (! $withinHours[$key]) {
                $out[$b->id] = self::CLOSED;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ shared

    /**
     * Up to three other times on $date with the same length that are free
     * right now (same capacity + working-hours rules), nearest first, each
     * with its price. A hint for the review — re-checked when booked.
     *
     * @return array<int, array{start: string, end: string, price: float}>
     */
    public function suggestions(array $draft, string $date, string $start, string $end, int $max = 3, bool $includeRequested = false): array
    {
        $toMin = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $fromMin = fn (int $m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $length = $toMin($end) - $toMin($start);
        if ($length <= 0) {
            return [];
        }

        $segments = $this->availability->freeBusyForDay($draft['room'], $date);
        if ($segments === []) {
            return [];
        }
        $dayStart = $toMin($segments[0]['start']);
        $last = end($segments);
        $dayEnd = $last['end'] === '24:00' ? 24 * 60 : $toMin($last['end']);
        $needed = $this->seats($draft['room'], $draft);
        $requested = $toMin($start);
        $candidates = [];

        for ($s = (int) (ceil($dayStart / 30) * 30); $s + $length <= $dayEnd; $s += 30) {
            if (($s === $requested && ! $includeRequested) || $s + $length > 24 * 60) {
                continue;
            }
            $e = $s + $length;
            $ok = collect($segments)->every(function ($seg) use ($toMin, $s, $e, $needed) {
                $a = $toMin($seg['start']);
                $b = $seg['end'] === '24:00' ? 24 * 60 : $toMin($seg['end']);

                return $b <= $s || $a >= $e || (! $seg['closed'] && $seg['available'] >= $needed);
            });
            if ($ok && $this->pastReason($date, $fromMin($s)) === null) {
                $candidates[] = $s;
            }
        }

        usort($candidates, fn ($a, $b) => [abs($a - $requested), $a] <=> [abs($b - $requested), $b]);

        $out = [];
        foreach (array_slice($candidates, 0, $max) as $s) {
            $price = $this->priceFor($draft, $date, $fromMin($s), $fromMin($s + $length));
            if ($price !== null) {
                $out[] = ['start' => $fromMin($s), 'end' => $fromMin($s + $length), 'price' => $price];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['start'], $b['start']));

        return $out;
    }

    /** Draft of an existing series (for booking later dates or changes). */
    public function draftOf(BookingSeries $series): array
    {
        return [
            'owner' => $series->owner,
            'room' => $series->room,
            'member' => $series->hotspotUser,
            'profile' => $series->pricingProfile,
            'rule' => $series->rule(),
            'start' => substr((string) $series->start_time, 0, 5),
            'end' => substr((string) $series->end_time, 0, 5),
            'party_size' => (int) $series->party_size,
            'guest_count' => $series->guest_count,
            'notes' => $series->notes,
            'package' => $series->member_package_id ? MemberPackage::where('owner_id', $series->owner_id)->find($series->member_package_id) : null,
            'payment_plan' => $series->payment_plan,
            'price' => $series->occurrence_price !== null ? [
                'rate' => (float) $series->price_per_hour,
                'total' => (float) $series->occurrence_price,
                'note' => $series->pricing_note,
                'minutes' => $series->lengthMinutes(),
            ] : null,
        ];
    }

    /**
     * One date's price: the series' agreed price for a date of the agreed
     * length (any time of day — e.g. another time picked for a conflict), so
     * later room price changes never reach it; otherwise (no agreement yet,
     * or a different length) the room's normal quote.
     */
    private function seriesQuote(array $draft, string $date, string $start, string $end): ?PriceQuote
    {
        $minutes = $this->lengthOf($start, $end);
        if (($price = $draft['price'] ?? null) && $price['minutes'] === $minutes) {
            return new PriceQuote('series', $price['total'], $minutes, $minutes, $price['rate'], $price['note'], null);
        }

        return $this->quote($draft, $date, $start, $end);
    }

    /**
     * Build a draft from validated input (the caller owner-scopes room,
     * member and profile).
     */
    public static function draft(Owner $owner, Room $room, HotspotUser $member, ?RoomPricingProfile $profile, RecurrenceRule $rule, array $v, ?MemberPackage $package = null): array
    {
        return [
            'package' => $package,
            'payment_plan' => $v['payment_plan'] ?? BookingSeries::PLAN_PER_SESSION,
            'price' => null,
            'owner' => $owner,
            'room' => $room,
            'member' => $member,
            'profile' => $profile,
            'rule' => $rule,
            'start' => $v['start_time'],
            'end' => $v['end_time'],
            'party_size' => (int) ($v['party_size'] ?? 1),
            'guest_count' => $room->isShared() ? null : (int) ($v['guest_count'] ?? 1),
            'notes' => $v['notes'] ?? null,
        ];
    }

    /** @return array{0: Booking[], 1: array<int, array>} */
    private function bookMany(BookingSeries $series, array $choices): array
    {
        $created = [];
        $failed = [];
        foreach ($choices as $c) {
            try {
                [$booking, $reason] = $this->bookOccurrence($series, $c['date'], $c['start'], $c['end']);
            } catch (QueryException $e) {
                // One date's database error (e.g. a deadlock past the retries)
                // is reported on the series instead of sinking the rest.
                report($e);
                [$booking, $reason] = [null, self::ERROR];
            }
            if ($booking) {
                $created[] = $booking;
            } elseif ($reason === self::EXISTS) {
                continue; // already booked by an earlier, interrupted run
            } else {
                $failed[] = ['date' => $c['date'], 'start' => $c['start'], 'end' => $c['end'], 'reason' => $reason];
            }
        }

        return [$created, $failed];
    }

    /** Pending/confirmed occurrences on or after $from's date (never past ones). */
    private function futureOccurrences(BookingSeries $series, Booking $from)
    {
        $fromDate = max($from->booking_date->toDateString(), today()->toDateString());

        return Booking::where('booking_series_id', $series->id)
            ->where('owner_id', $series->owner_id)
            ->whereDate('booking_date', '>=', $fromDate)
            ->whereIn('status', ['pending', 'confirmed'])
            ->orderBy('booking_date')->orderBy('start_time')
            ->get();
    }

    /** Non-locking check for the review: why $date can't be booked now, or null. */
    private function checkSlot(array $draft, string $date, string $start, string $end): ?string
    {
        if ($reason = $this->pastReason($date, $start)) {
            return $reason;
        }
        if (! $draft['room']->is_available) {
            return self::ROOM_OFF;
        }
        if (! $this->businessHours->isWithinWorkingHours($draft['owner'], $date, $start, $end)) {
            return self::CLOSED;
        }
        if ($this->availability->availabilityForRange($draft['room'], $date, $start, $end) < $this->seats($draft['room'], $draft)) {
            return self::BOOKED;
        }

        return null;
    }

    private function pastReason(string $date, string $start): ?string
    {
        $today = today()->toDateString();
        if ($date < $today || ($date === $today && $start <= now()->format('H:i'))) {
            return self::PAST;
        }

        return null;
    }

    /** Seats an occurrence takes: the party in a shared room, the whole room otherwise. */
    private function seats(Room $room, array $draft): int
    {
        return $room->isShared() ? max(1, (int) $draft['party_size']) : 1;
    }

    private function priceFor(array $draft, string $date, string $start, string $end): ?float
    {
        return $this->quote($draft, $date, $start, $end)?->totalPrice;
    }

    /** The same quote a one-time booking gets (people = party in a shared room, guests otherwise). */
    private function quote(array $draft, string $date, string $start, string $end)
    {
        $people = $draft['room']->isShared() ? (int) $draft['party_size'] : (int) ($draft['guest_count'] ?? 1);
        try {
            return $this->pricing->quoteBooking($draft['room'], max(1, $people), $date, $start, $end, $draft['profile']);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
