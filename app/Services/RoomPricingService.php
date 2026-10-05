<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Owner;
use App\Models\Room;
use App\Models\RoomPlan;
use App\Models\RoomPricingProfile;
use App\Models\SharedSession;
use App\Support\Money;
use App\Support\Pricing\PriceQuote;
use App\Support\Pricing\PricingRules;
use Carbon\Carbon;

/**
 * THE source of truth for "Room + number of people + duration → price".
 * Every place that charges or quotes goes through here: booking create/edit,
 * room quotes (booking form cards, availability lookup), shared-session
 * estimate/preview/close and check-in snapshots. Financials only ever read
 * the totals this service produced and that were stored on bookings — they
 * never re-price.
 *
 * Hourly rooms (every room that existed before flexible pricing) are
 * delegated to the two original formulas, unchanged:
 *   bookings        → BookingService::calculateBooking() (hours × rate)
 *   shared sessions → SharedSessionBillingService::calculate() (billing_unit blocks)
 *
 * Rule-based rooms (PricingRules):
 *   · People tier  = the first tier whose upper bound fits the party; larger
 *     parties use the last tier.
 *   · people model = time × that tier's hourly rate.
 *   · duration / people_duration = the cheapest owner-defined duration option
 *     that covers the time (so 90 min with 1h/2h options is the 2h price —
 *     every started package counts, like block billing). Full Day is an option
 *     whose length is that date's business day (BusinessHoursService), or the
 *     whole calendar day when hours aren't configured — so it also acts as the
 *     day's price cap. Longer than every option (no Full Day): the longest
 *     option plus the extra time at that option's own per-minute rate.
 *
 * Pricing Profiles (RoomPricingProfile) are optional owner-named hourly rates
 * ("Photography — 700/hr"). A booking/session priced with one is plain hourly
 * at that rate — through the same BookingService / SharedSessionBillingService
 * formulas (billing unit + grace buffer still apply) — instead of the room's
 * default pricing. Precedence per booking: Custom Plan · Pricing Profile ·
 * room default — exactly one applies, never combined.
 *
 * Custom Plans (RoomPlan) sit beside the room's rules: a fixed price for
 * exactly N people over the plan's own duration (quotePlan/planWindow). A
 * checked-in plan reservation bills the plan price plus any time beyond it at
 * the room's standard pricing (quoteSession).
 */
class RoomPricingService
{
    public function __construct(
        private BookingService $bookings,
        private SharedSessionBillingService $billing,
        private BusinessHoursService $businessHours,
    ) {}

    /** Price a reservation of $room for $people between $start and $end on $date (optionally at a pricing profile's rate). */
    public function quoteBooking(Room $room, int $people, string $date, string $start, string $end, ?RoomPricingProfile $profile = null): PriceQuote
    {
        if ($profile) {
            $rate = (float) $profile->price_per_hour;
            $calc = $this->bookings->calculateBooking($start, $end, $rate);
            $minutes = $calc['total_hours'] * 60;

            return new PriceQuote(PricingRules::HOURLY, $calc['total_price'], $minutes, $minutes, $rate,
                $profile->name.' · '.$profile->rateLabel(), $rate);
        }

        $rules = $room->pricingRules();

        if ($rules->isHourly()) {
            $calc = $this->bookings->calculateBooking($start, $end, (float) $room->price_per_hour);
            $minutes = $calc['total_hours'] * 60;

            return new PriceQuote(PricingRules::HOURLY, $calc['total_price'], $minutes, $minutes,
                (float) $room->price_per_hour, null, (float) $room->price_per_hour);
        }

        $minutes = (float) Carbon::parse($start)->diffInMinutes(Carbon::parse($end));
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('End time must be after start time.');
        }

        return $this->quoteRules($rules, max(1, $people), $minutes, $this->fullDayMinutes($room->owner, $date));
    }

    /**
     * Running or final bill for a shared session, from opened_at → $closedAt.
     * Uses the rules snapshotted when the session opened (never the room's
     * current rules) — or, for a session with no snapshot, the legacy
     * snapshotted billing_unit/billed_price_per_hour path bit-for-bit.
     */
    public function quoteSession(SharedSession $session, Carbon $closedAt): PriceQuote
    {
        if ($plan = $session->plan_snapshot) {
            return $this->quotePlanSession($session, $plan, $closedAt);
        }

        return $this->standardSessionQuote($session, $session->opened_at, $closedAt);
    }

    /**
     * When this session's running bill next goes up — for block billing
     * (half-hour/hour, with its snapshotted grace buffer), so Active Sessions
     * can say "Next hour in 4 min". Null when the price isn't block-based
     * (per-minute, rule-based packages) or a Custom Plan still covers the time.
     */
    public function nextSessionChargeAt(SharedSession $session, Carbon $now): ?Carbon
    {
        $from = $session->opened_at;
        if ($plan = $session->plan_snapshot) {
            $from = $session->opened_at->copy()->addSeconds((int) round((float) $plan['minutes'] * 60));
            if ($now->lte($from)) {
                return null;
            }
        }

        if ($session->pricing_snapshot) {
            return null;
        }

        return $this->billing->nextChargeAt($from, $now, $session->billing_unit ?? 'minute', (int) ($session->billing_buffer_minutes ?? 0));
    }

    /**
     * Running or final bill for an open-status exclusive Booking (Open
     * Session), from its start_time to $closedAt. Mirrors
     * standardSessionQuote()'s hourly branch exactly, reading the booking's
     * own snapshotted billing_unit/billing_buffer_minutes/price_per_hour
     * instead of a SharedSession's — Open Session is hourly/profile pricing
     * only (never rule-based, never a Custom Plan), so there is no
     * pricing_snapshot/plan branch to mirror here.
     */
    public function quoteOpenBooking(Booking $booking, Carbon $closedAt): PriceQuote
    {
        $from = $booking->startsAt();
        $unit = $booking->billing_unit ?? 'minute';
        $rate = (float) $booking->price_per_hour;
        $billed = $this->billing->calculate($from, $closedAt, $unit, $rate, (int) ($booking->billing_buffer_minutes ?? 0));

        // Priced with a profile: say so ("Cinema · EGP 500.00/hr").
        $note = $booking->pricing_profile_name
            ? $booking->pricing_profile_name.' · '.Money::format($rate).__('app.common.slash_hr')
            : null;

        return new PriceQuote(PricingRules::HOURLY, $billed['total_price'], $billed['total_minutes'],
            $billed['billed_minutes'], $rate, $note, $unit === 'minute' ? $rate : null);
    }

    /**
     * When an open booking's running bill next goes up (block billing with
     * its snapshotted grace buffer) — mirrors nextSessionChargeAt() minus the
     * plan-snapshot branch (Open Session never has a Custom Plan).
     */
    public function nextOpenBookingChargeAt(Booking $booking, Carbon $now): ?Carbon
    {
        return $this->billing->nextChargeAt($booking->startsAt(), $now, $booking->billing_unit ?? 'minute', (int) ($booking->billing_buffer_minutes ?? 0));
    }

    /** The session's standard (non-plan) bill for the time between $from and $to. */
    private function standardSessionQuote(SharedSession $session, Carbon $from, Carbon $closedAt): PriceQuote
    {
        if (! $session->pricing_snapshot) {
            $unit = $session->billing_unit ?? 'minute';
            $rate = (float) ($session->billed_price_per_hour ?? $session->room->price_per_hour);
            $billed = $this->billing->calculate($from, $closedAt, $unit, $rate, (int) ($session->billing_buffer_minutes ?? 0));

            // Priced with a profile: say so ("Photography · EGP 15.00/hr").
            $note = $session->pricing_profile_name
                ? $session->pricing_profile_name.' · '.Money::format($rate).__('app.common.slash_hr')
                : null;

            return new PriceQuote(PricingRules::HOURLY, $billed['total_price'], $billed['total_minutes'],
                $billed['billed_minutes'], $rate, $note, $unit === 'minute' ? $rate : null);
        }

        $rules = PricingRules::fromStored($session->pricing_snapshot['model'] ?? null, $session->pricing_snapshot['rules'] ?? null);
        $minutes = max(0.0, round($from->diffInSeconds($closedAt) / 60, 2)); // never negative (signed diff)
        $date = $session->session_date?->format('Y-m-d') ?? $session->opened_at->format('Y-m-d');

        return $this->quoteRules($rules, max(1, (int) $session->party_size), $minutes, $this->fullDayMinutes($session->room->owner, $date));
    }

    /**
     * Plan price for the plan's time, then the room's standard pricing for only
     * the minutes beyond it (never re-charging the plan's own time).
     */
    private function quotePlanSession(SharedSession $session, array $plan, Carbon $closedAt): PriceQuote
    {
        $used = max(0.0, round($session->opened_at->diffInSeconds($closedAt) / 60, 2));
        $planMinutes = (float) $plan['minutes'];
        $price = (float) $plan['price'];

        if ($used <= $planMinutes) {
            return new PriceQuote('plan', round($price, 2), $used, $planMinutes, round($price / max(1, $planMinutes / 60), 2), $plan['note'], null);
        }

        $extra = $this->standardSessionQuote($session, $session->opened_at->copy()->addSeconds((int) round($planMinutes * 60)), $closedAt);
        $total = round($price + $extra->totalPrice, 2);

        return new PriceQuote('plan', $total, $used, $planMinutes + $extra->billedMinutes,
            round($total / max(1 / 60, $used / 60), 2),
            __('app.plans.note_overtime', ['plan' => $plan['note'], 'extra' => PricingRules::minutesLabel((int) ceil($used - $planMinutes))]),
            null);
    }

    /**
     * The window a plan books when it starts at $start on $date: start + the
     * plan's minutes, or that date's working-hours window for a Full Day plan
     * (the whole rest of the day when hours aren't configured). Null when the
     * plan doesn't fit in the day from that start.
     *
     * @return array{start: string, end: string, minutes: int}|null
     */
    public function planWindow(Room $room, RoomPlan $plan, string $date, string $start): ?array
    {
        if ($plan->is_full_day) {
            $window = $this->businessHours->fullDayWindow($room->owner, $date);
            if ($window) {
                $end = $window['end'] === '24:00' ? '23:59' : $window['end'];

                return ['start' => $window['start'], 'end' => $end, 'minutes' => $this->toMinutes($end) - $this->toMinutes($window['start'])];
            }
            $start = substr($start, 0, 5);

            return ['start' => $start, 'end' => '23:59', 'minutes' => 1439 - $this->toMinutes($start)];
        }

        $startMin = $this->toMinutes(substr($start, 0, 5));
        $endMin = $startMin + (int) $plan->duration_minutes;
        if ($endMin > 1440) {
            return null;
        }
        $endMin = min($endMin, 1439); // a plan ending exactly at midnight books up to 23:59

        return ['start' => substr($start, 0, 5), 'end' => sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60), 'minutes' => $endMin - $startMin];
    }

    /**
     * A Custom Plan's fixed price for a booking. The plan is for exactly its
     * people count; it decides the duration (planWindow). Throws when the plan
     * doesn't belong to the room, the people count differs, or it can't fit.
     */
    public function quotePlan(Room $room, RoomPlan $plan, int $people, string $date, string $start): PriceQuote
    {
        if ((int) $plan->room_id !== (int) $room->id) {
            throw new \InvalidArgumentException('plan_room');
        }
        if ($people !== (int) $plan->people) {
            throw new \InvalidArgumentException('plan_people');
        }
        $window = $this->planWindow($room, $plan, $date, $start);
        if (! $window || $window['minutes'] <= 0) {
            throw new \InvalidArgumentException('plan_window');
        }

        $price = round((float) $plan->price, 2);

        return new PriceQuote('plan', $price, (float) $window['minutes'], (float) $window['minutes'],
            round($price / ($window['minutes'] / 60), 2), $plan->note(), null);
    }

    /**
     * Frozen onto the shared session when a plan reservation is checked in:
     * taken from the booking itself (what was actually sold), so a later edit
     * or deletion of the plan can't change the session's bill.
     */
    public function planSnapshotFor(Booking $booking): ?array
    {
        if (! $booking->room_plan_id) {
            return null;
        }

        return [
            'note' => $booking->pricing_note,
            'minutes' => $this->toMinutes(substr((string) $booking->end_time, 0, 5)) - $this->toMinutes(substr((string) $booking->start_time, 0, 5)),
            'price' => (float) $booking->total_price,
        ];
    }

    private function toMinutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return $h * 60 + $m;
    }

    /**
     * A room's pricing profile, owner + room scoped (404 otherwise). Only an
     * active one may be newly chosen — except $keepId, the profile a booking
     * already holds, so editing it after the profile was deactivated still works.
     */
    public function resolveProfile(Room $room, ?int $profileId, ?int $keepId = null): ?RoomPricingProfile
    {
        if (! $profileId) {
            return null;
        }

        return RoomPricingProfile::where('owner_id', $room->owner_id)
            ->where('room_id', $room->id)
            ->whereKey($profileId)
            ->when($profileId !== $keepId, fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
    }

    /** What a session opened now must freeze; null keeps the legacy hourly path (always, when priced by a profile). */
    public function snapshotFor(Room $room, ?RoomPricingProfile $profile = null): ?array
    {
        if ($profile) {
            return null;
        }

        $rules = $room->pricingRules();

        return $rules->isHourly() ? null : ['model' => $rules->model, 'rules' => $rules->toArray()];
    }

    /** Minutes "Full Day" stands for on $date for this owner. */
    public function fullDayMinutes(?Owner $owner, string $date): int
    {
        $window = $owner ? $this->businessHours->fullDayWindow($owner, $date) : null;

        return $window['minutes'] ?? 24 * 60;
    }

    /** Short, owner-facing summary for room lists and cards: "EGP 100.00/hr", "From EGP 50.00". */
    public function summary(Room $room): string
    {
        $rules = $room->pricingRules();

        return match ($rules->model) {
            PricingRules::HOURLY => Money::format((float) $room->price_per_hour).__('app.common.slash_hr'),
            PricingRules::PEOPLE => __('app.pricing.from', ['price' => Money::format($rules->lowestPrice())]).__('app.common.slash_hr'),
            default => __('app.pricing.from', ['price' => Money::format($rules->lowestPrice())]),
        };
    }

    private function quoteRules(PricingRules $rules, int $people, float $minutes, int $fullDayMinutes): PriceQuote
    {
        $row = $rules->tierIndexFor($people);
        $peopleNote = $rules->usesPeople() ? $rules->peopleLabel($row) : null;

        // Time-based rate per tier (people model).
        if (! $rules->usesDurations()) {
            $rate = (float) $rules->prices[$row][0];
            $total = round(round($minutes / 60, 4) * $rate, 2);

            return new PriceQuote($rules->model, $total, $minutes, $minutes, $rate,
                $peopleNote.' · '.Money::format($rate).__('app.common.slash_hr'), $rate);
        }

        // Package-based (duration, people_duration).
        $lengths = [];
        foreach ($rules->durations as $col => $d) {
            $lengths[$col] = $rules->isFullDay($col) ? $fullDayMinutes : (int) $d['minutes'];
        }

        $best = null;
        foreach ($lengths as $col => $length) {
            if ($length >= $minutes) {
                $price = (float) $rules->prices[$row][$col];
                if ($best === null || $price < $best['price'] || ($price === $best['price'] && $length < $best['length'])) {
                    $best = ['col' => $col, 'price' => $price, 'length' => $length];
                }
            }
        }

        if ($best) {
            $total = round($best['price'], 2);
            $billed = (float) $best['length'];
            $note = $rules->durationLabel($best['col']);
        } else {
            // Longer than every option and no Full Day covers it: the longest
            // option plus the extra time pro-rata at that option's own rate.
            $col = array_keys($lengths, max($lengths))[0];
            $length = $lengths[$col];
            $price = (float) $rules->prices[$row][$col];
            $extra = $minutes - $length;
            $total = round($price + $extra * ($price / $length), 2);
            $billed = $minutes;
            $note = __('app.pricing.note_extra', [
                'base' => $rules->durationLabel($col),
                'extra' => PricingRules::minutesLabel((int) ceil($extra)),
            ]);
        }

        $rate = $minutes > 0 ? round($total / ($minutes / 60), 2) : 0.0;

        return new PriceQuote($rules->model, $total, $minutes, $billed, $rate,
            $peopleNote ? $note.' · '.$peopleNote : $note, null);
    }
}
