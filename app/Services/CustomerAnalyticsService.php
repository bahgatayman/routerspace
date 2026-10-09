<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CustomerAnalyticsService
{
    /** Below this many active customers in the period, a repeat/return % would be swayed by one or two people — omit it instead. */
    public const MIN_CUSTOMERS_FOR_RATE = 5;

    public function totalCustomers(Owner $owner): int
    {
        return HotspotUser::where('owner_id', $owner->id)->count();
    }

    /**
     * HotspotUser rows created within the period. HotspotUser is also the
     * hotspot/network-login entity, not a booking-only customer list — this
     * count can include hotspot signups with no bookings/sales, so callers
     * should label it as "new people in the system," not "new paying
     * customers."
     */
    public function newCustomers(Owner $owner, AnalyticsPeriod $period): int
    {
        return HotspotUser::where('owner_id', $owner->id)
            ->whereBetween('created_at', [$period->start, $period->end])
            ->count();
    }

    /**
     * Of the customers who booked or bought something in the period, what
     * share had also booked/bought something before the period started.
     * Null (not 0%) when the active-customer count is too small for the
     * percentage to mean anything (see MIN_CUSTOMERS_FOR_RATE).
     *
     * @return array{returning:int, total:int, percent:float}|null
     */
    public function returningCustomerRate(Owner $owner, AnalyticsPeriod $period): ?array
    {
        // Both lookups always run, win or lose on the threshold below — a
        // page's query count shouldn't depend on how much data an owner
        // happens to have (the same invariant DashboardAnalyticsTest's
        // query-count-does-not-grow-with-room-count test already enforces
        // elsewhere on this page).
        $active = $this->customerIdsActiveBetween($owner, $period->start, $period->end);
        $priorIds = $this->customerIdsActiveBefore($owner, $period->start);

        $total = $active->count();
        if ($total < self::MIN_CUSTOMERS_FOR_RATE) {
            return null;
        }

        $returning = $active->intersect($priorIds)->count();

        return [
            'returning' => $returning,
            'total' => $total,
            'percent' => round(($returning / $total) * 100, 1),
        ];
    }

    /**
     * (Booking + sale revenue) ÷ distinct active customers, for the period.
     * Null when nobody was active — never divides by zero.
     */
    public function averageSpendPerCustomer(Owner $owner, AnalyticsPeriod $period): ?float
    {
        $active = $this->customerIdsActiveBetween($owner, $period->start, $period->end);

        if ($active->isEmpty()) {
            return null;
        }

        $bookingRevenue = (float) Booking::where('owner_id', $owner->id)
            ->revenueRecognised()
            ->whereIn('hotspot_user_id', $active)
            ->whereDateBetween('booking_date', $period->startDate(), $period->endDate())
            ->sum('amount_paid');

        $saleRevenue = (float) Sale::where('owner_id', $owner->id)
            ->completed()
            ->whereIn('hotspot_user_id', $active)
            ->whereBetween('sold_at', [$period->start, $period->end])
            ->sum('total');

        return round(($bookingRevenue + $saleRevenue) / $active->count(), 2);
    }

    /** Distinct hotspot_user_id with a non-cancelled booking or completed sale inside [start, end]. */
    private function customerIdsActiveBetween(Owner $owner, Carbon $start, Carbon $end): Collection
    {
        $bookingIds = Booking::where('owner_id', $owner->id)
            ->countsTowardGbv()
            ->whereNotNull('hotspot_user_id')
            ->whereDateBetween('booking_date', $start->toDateString(), $end->toDateString())
            ->pluck('hotspot_user_id');

        $saleIds = Sale::where('owner_id', $owner->id)
            ->completed()
            ->whereNotNull('hotspot_user_id')
            ->whereBetween('sold_at', [$start, $end])
            ->pluck('hotspot_user_id');

        return $bookingIds->merge($saleIds)->unique()->values();
    }

    /** Distinct hotspot_user_id with a non-cancelled booking or completed sale strictly before $before. */
    private function customerIdsActiveBefore(Owner $owner, Carbon $before): Collection
    {
        $bookingIds = Booking::where('owner_id', $owner->id)
            ->countsTowardGbv()
            ->whereNotNull('hotspot_user_id')
            ->where('booking_date', '<', $before->toDateString()) // = whereDate '<' (Y-m-d prefix), but index-friendly
            ->pluck('hotspot_user_id');

        $saleIds = Sale::where('owner_id', $owner->id)
            ->completed()
            ->whereNotNull('hotspot_user_id')
            ->where('sold_at', '<', $before)
            ->pluck('hotspot_user_id');

        return $bookingIds->merge($saleIds)->unique()->values();
    }
}
