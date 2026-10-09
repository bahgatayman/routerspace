<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Owner;
use App\Models\Sale;
use App\Models\SaleItem;

/**
 * Combines Booking.amount_paid (room revenue — cash actually collected, not
 * billed value) and Sale.total (product/service revenue, still assumed
 * fully settled — see SaleItem/SalesService, there is no partial-payment
 * concept there) for a period. Deliberately never sums
 * SharedSession.total_price separately: on SharedSession::close(), the
 * controller auto-creates a completed Booking carrying the identical
 * total_price (and amount_paid, always fully paid at close) and links it
 * via shared_sessions.booking_id — a closed session and its spawned booking
 * describe one economic event twice, so summing both here would
 * double-count it. Only bookingRevenue()/totalRevenue() are the source of
 * truth for money earned.
 */
class RevenueAnalyticsService
{
    /** $workspaceId (optional) narrows to one location's rooms — same money rule. */
    public function bookingRevenue(Owner $owner, AnalyticsPeriod $period, ?int $workspaceId = null): float
    {
        return (float) Booking::where('owner_id', $owner->id)
            ->revenueRecognised()
            ->whereDateBetween('booking_date', $period->startDate(), $period->endDate())
            ->when($workspaceId, fn ($q, $id) => $q->whereHas('room', fn ($r) => $r->where('workspace_id', $id)))
            ->sum('amount_paid');
    }

    public function saleRevenue(Owner $owner, AnalyticsPeriod $period): float
    {
        return (float) Sale::where('owner_id', $owner->id)
            ->completed()
            ->whereBetween('sold_at', [$period->start, $period->end])
            ->sum('total');
    }

    public function totalRevenue(Owner $owner, AnalyticsPeriod $period): float
    {
        return $this->bookingRevenue($owner, $period) + $this->saleRevenue($owner, $period);
    }

    /**
     * Current-period totals plus the immediately preceding equal-length
     * period, so a caller can render a "vs previous period" delta without
     * re-running the queries itself.
     *
     * @return array{current: float, previous: float, change: float, changePercent: ?float}
     */
    public function revenueWithComparison(Owner $owner, AnalyticsPeriod $period): array
    {
        $current = $this->totalRevenue($owner, $period);
        $previous = $this->totalRevenue($owner, $period->previous());

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => round($current - $previous, 2),
            'changePercent' => $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null,
        ];
    }

    /** Completed-booking revenue ÷ completed-booking count for the period. Null if there are none. */
    public function averageBookingValue(Owner $owner, AnalyticsPeriod $period): ?float
    {
        $completed = Booking::where('owner_id', $owner->id)
            ->where('status', 'completed')
            ->whereDateBetween('booking_date', $period->startDate(), $period->endDate());

        $count = (clone $completed)->count();

        if ($count === 0) {
            return null;
        }

        return round(((float) (clone $completed)->sum('amount_paid')) / $count, 2);
    }

    /**
     * Daily revenue series across the period, combining Booking + Sale by
     * date and zero-filling days with no revenue at all. Two grouped
     * queries total regardless of the period's length — never a per-day
     * loop over the database. Dates are normalized through SQL date() on
     * both sides: booking_date is a `date`-cast column that Eloquent
     * serializes with a time component on write, so a bare string key
     * wouldn't reliably line up with plain 'Y-m-d' — see the identical
     * concern documented on Room::hasConflict().
     *
     * @return array<string, float> date (Y-m-d) => revenue, one entry per day in the period
     */
    public function dailyRevenueTrend(Owner $owner, AnalyticsPeriod $period): array
    {
        $bookingByDate = Booking::where('owner_id', $owner->id)
            ->where('status', 'completed')
            ->whereDateBetween('booking_date', $period->startDate(), $period->endDate())
            ->selectRaw('date(booking_date) as d, SUM(amount_paid) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $saleByDate = Sale::where('owner_id', $owner->id)
            ->where('status', 'completed')
            ->whereBetween('sold_at', [$period->start, $period->end])
            ->selectRaw('date(sold_at) as d, SUM(total) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $trend = [];
        $cursor = $period->start->copy()->startOfDay();
        while ($cursor->lte($period->end)) {
            $date = $cursor->toDateString();
            $trend[$date] = round((float) ($bookingByDate[$date] ?? 0) + (float) ($saleByDate[$date] ?? 0), 2);
            $cursor->addDay();
        }

        return $trend;
    }

    /**
     * Completed-booking revenue grouped by room, for the Financials module's
     * breakdown tables/export. Same completed-only, date-ranged filter as
     * bookingRevenue() — never a looser status set.
     *
     * @return array<int, array{room_id: int, room_name: string, revenue: float, bookings: int}>
     */
    public function revenueByRoom(Owner $owner, AnalyticsPeriod $period): array
    {
        return Booking::where('bookings.owner_id', $owner->id)
            ->where('bookings.status', 'completed')
            ->whereDateBetween('bookings.booking_date', $period->startDate(), $period->endDate())
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->selectRaw('rooms.id as room_id, rooms.name as room_name, SUM(bookings.amount_paid) as revenue, COUNT(*) as bookings')
            ->groupBy('rooms.id', 'rooms.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'room_id' => (int) $row->room_id,
                'room_name' => $row->room_name,
                'revenue' => round((float) $row->revenue, 2),
                'bookings' => (int) $row->bookings,
            ])
            ->all();
    }

    /**
     * Completed-booking revenue grouped by room type (meeting/training/
     * shared/office). Same completed-only filter as bookingRevenue().
     *
     * @return array<int, array{type: string, revenue: float, bookings: int}>
     */
    public function revenueByRoomType(Owner $owner, AnalyticsPeriod $period): array
    {
        return Booking::where('bookings.owner_id', $owner->id)
            ->where('bookings.status', 'completed')
            ->whereDateBetween('bookings.booking_date', $period->startDate(), $period->endDate())
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->selectRaw('rooms.type as type, SUM(bookings.amount_paid) as revenue, COUNT(*) as bookings')
            ->groupBy('rooms.type')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'revenue' => round((float) $row->revenue, 2),
                'bookings' => (int) $row->bookings,
            ])
            ->all();
    }

    /**
     * Line-item revenue grouped by product, sourced from items on completed
     * Sales only (mirrors saleRevenue()'s status/date filter, just applied
     * one level down at the SaleItem). Falls back to the item's own
     * snapshotted name when product_id is null (source Product later
     * deleted — sale_items.product_id is nullOnDelete) so a removed product
     * doesn't silently vanish from historical revenue breakdowns.
     *
     * @return array<int, array{product_id: ?int, name: string, revenue: float, quantity: int}>
     */
    public function revenueByProduct(Owner $owner, AnalyticsPeriod $period): array
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.owner_id', $owner->id)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sold_at', [$period->start, $period->end])
            ->selectRaw('sale_items.product_id as product_id, sale_items.name as name, SUM(sale_items.line_total) as revenue, SUM(sale_items.quantity) as quantity')
            ->groupBy('sale_items.product_id', 'sale_items.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'name' => $row->name,
                'revenue' => round((float) $row->revenue, 2),
                'quantity' => (int) $row->quantity,
            ])
            ->all();
    }
}
