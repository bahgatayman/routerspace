<?php

namespace App\Services;

use App\Models\Owner;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Collection;

/**
 * Product/inventory analytics for the Owner Dashboard's Products section — a
 * sibling to RevenueAnalyticsService, not an extension of it: that service
 * is scoped narrowly to money reconciliation (Booking vs SharedSession
 * double-counting); this one answers catalog-shaped questions (per-product
 * breakdown, order counts, stock, insights) that service was never meant to
 * carry.
 *
 * Every query here is anchored on Sale::completed() (cancelled/open sales
 * never count — the same money rule every report already shares) joined
 * down to sale_items/products, filtered to products.type='product' (never
 * services — matches Product::scopeLowStock()'s own filter and the
 * feature's own name). A sale_items row whose product_id is null (the
 * source Product was later deleted — sale_items.product_id is
 * nullOnDelete) is deliberately EXCLUDED here via the inner join, unlike
 * RevenueAnalyticsService::revenueByProduct() which keeps such rows (it
 * cares about total revenue completeness, not a per-product catalog view) —
 * see ProductAnalyticsServiceTest's dedicated divergence test.
 */
class ProductAnalyticsService
{
    /** A room type needs at least this many product-bearing bookings before its per-booking average is trustworthy enough to compare against another type. */
    public const MIN_BOOKINGS_FOR_ROOM_TYPE_COMPARISON = 3;

    /**
     * @return array{topProduct: ?array, totalUnits: int, productRevenue: float, avgOrderValue: ?float, orderCount: int, lowStockCount: int}
     */
    public function summary(Owner $owner, AnalyticsPeriod $period): array
    {
        $totals = $this->baseQuery($owner, $period)
            ->selectRaw('SUM(sale_items.quantity) as units, SUM(sale_items.line_total) as revenue, COUNT(DISTINCT sales.id) as orders')
            ->first();

        $units = (int) ($totals->units ?? 0);
        $revenue = round((float) ($totals->revenue ?? 0), 2);
        $orders = (int) ($totals->orders ?? 0);

        return [
            'topProduct' => $this->topProducts($owner, $period)[0] ?? null,
            'totalUnits' => $units,
            'productRevenue' => $revenue,
            'avgOrderValue' => $orders > 0 ? round($revenue / $orders, 2) : null,
            'orderCount' => $orders,
            'lowStockCount' => Product::where('owner_id', $owner->id)->lowStock()->count(),
        ];
    }

    /**
     * Daily units/revenue/orders, zero-filled across every day in the
     * period — one grouped query + a PHP day-cursor, the same idiom as
     * RevenueAnalyticsService::dailyRevenueTrend() (never a per-day query).
     * One payload drives every metric toggle on the trend chart client-side.
     *
     * @return array<string, array{units: int, revenue: float, orders: int}>
     */
    public function dailySeries(Owner $owner, AnalyticsPeriod $period): array
    {
        $byDate = $this->baseQuery($owner, $period)
            ->selectRaw('date(sales.sold_at) as d, SUM(sale_items.quantity) as units, SUM(sale_items.line_total) as revenue, COUNT(DISTINCT sales.id) as orders')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $series = [];
        $cursor = $period->start->copy()->startOfDay();
        while ($cursor->lte($period->end)) {
            $date = $cursor->toDateString();
            $row = $byDate[$date] ?? null;
            $series[$date] = [
                'units' => (int) ($row->units ?? 0),
                'revenue' => round((float) ($row->revenue ?? 0), 2),
                'orders' => (int) ($row->orders ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Every product with at least one sale in the period, revenue-desc. No
     * LIMIT — bounded by distinct product count (itself capped by
     * Plan::max_products), never by sale-row count. Drives the top-products
     * chart (re-sortable by units/revenue client-side from this one
     * payload) and is reused by summary()/insights() rather than re-queried.
     *
     * @return array<int, array{product_id: int, name: string, units: int, revenue: float, orders: int}>
     */
    public function topProducts(Owner $owner, AnalyticsPeriod $period): array
    {
        return $this->baseQuery($owner, $period)
            ->selectRaw('sale_items.product_id as product_id, sale_items.name as name, SUM(sale_items.quantity) as units, SUM(sale_items.line_total) as revenue, COUNT(DISTINCT sale_items.sale_id) as orders')
            ->groupBy('sale_items.product_id', 'sale_items.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'units' => (int) $row->units,
                'revenue' => round((float) $row->revenue, 2),
                'orders' => (int) $row->orders,
            ])
            ->all();
    }

    /**
     * Product revenue per completed booking, grouped by the booked room's
     * type — join sale_items -> sales -> bookings -> rooms via
     * sales.booking_id (a standalone walk-in sale with no booking_id is
     * excluded by the inner join, the same treatment baseQuery() already
     * gives a deleted product's line). A room type with too few
     * product-bearing bookings is dropped entirely rather than returned
     * with a shaky average (see MIN_BOOKINGS_FOR_ROOM_TYPE_COMPARISON).
     *
     * @return array<int, array{roomType: string, productRevenuePerBooking: float, bookingCount: int}>
     */
    public function productSpendByRoomType(Owner $owner, AnalyticsPeriod $period): array
    {
        // Built directly rather than via baseQuery() — Sale::completed()
        // filters on the unqualified 'status' column, which becomes
        // ambiguous once 'bookings' (which also has a 'status' column) is
        // joined in below; 'sales.status' sidesteps that.
        return Sale::where('sales.owner_id', $owner->id)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sold_at', [$period->start, $period->end])
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('products.type', 'product')
            ->join('bookings', 'bookings.id', '=', 'sales.booking_id')
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->selectRaw('rooms.type as room_type, SUM(sale_items.line_total) as revenue, COUNT(DISTINCT bookings.id) as bookings')
            ->groupBy('rooms.type')
            ->get()
            ->map(fn ($row) => [
                'roomType' => $row->room_type,
                'bookingCount' => (int) $row->bookings,
                'productRevenuePerBooking' => $row->bookings > 0 ? round((float) $row->revenue / (int) $row->bookings, 2) : 0.0,
            ])
            ->filter(fn (array $row) => $row['bookingCount'] >= self::MIN_BOOKINGS_FOR_ROOM_TYPE_COMPARISON)
            ->values()
            ->all();
    }

    /** @return Collection<int, Product> */
    public function lowStockProducts(Owner $owner): Collection
    {
        return Product::where('owner_id', $owner->id)
            ->lowStock()
            ->orderBy('stock_quantity')
            ->get();
    }

    /**
     * Natural-language callouts, each reusing data already computed above —
     * never a 3rd re-derivation of the same predicate/query.
     *
     * @return array<int, array{type: string, text: string}>
     */
    public function insights(Owner $owner, AnalyticsPeriod $period): array
    {
        $insights = [];
        $top = $this->topProducts($owner, $period);

        if ($top !== []) {
            $bestSeller = collect($top)->sortByDesc('units')->first();
            $insights[] = [
                'type' => 'best_seller',
                'text' => __('app.dashboard.insight_best_seller', ['name' => $bestSeller['name'], 'units' => $bestSeller['units']]),
            ];

            $highestRevenue = $top[0]; // already revenue-desc
            if ($highestRevenue['product_id'] !== $bestSeller['product_id']) {
                $insights[] = [
                    'type' => 'highest_revenue',
                    'text' => __('app.dashboard.insight_highest_revenue', ['name' => $highestRevenue['name']]),
                ];
            }

            $previous = collect($this->topProducts($owner, $period->previous()))->keyBy('product_id');
            $withDelta = collect($top)->map(fn ($row) => [
                ...$row,
                'delta' => $row['revenue'] - (float) ($previous[$row['product_id']]['revenue'] ?? 0),
            ]);

            $biggestIncrease = $withDelta->sortByDesc('delta')->first();
            if ($biggestIncrease && $biggestIncrease['delta'] > 0) {
                $insights[] = [
                    'type' => 'biggest_increase',
                    'text' => __('app.dashboard.insight_biggest_increase', ['name' => $biggestIncrease['name'], 'amount' => number_format($biggestIncrease['delta'], 2)]),
                ];
            }

            $biggestDecrease = $withDelta->sortBy('delta')->first();
            if ($biggestDecrease && $biggestDecrease['delta'] < 0) {
                $insights[] = [
                    'type' => 'declining_sales',
                    'text' => __('app.dashboard.insight_declining_sales', ['name' => $biggestDecrease['name'], 'amount' => number_format(abs($biggestDecrease['delta']), 2)]),
                ];
            }

            // Units sold per day open — a product can rank low on total units
            // yet still be "fastest selling" over a short period; not the
            // same question biggest-revenue/biggest-seller already answer.
            $daysInPeriod = max(1, $period->start->diffInDays($period->end) + 1);
            $fastest = collect($top)->sortByDesc(fn ($row) => $row['units'] / $daysInPeriod)->first();
            if ($fastest && $fastest['units'] / $daysInPeriod > 0) {
                $insights[] = [
                    'type' => 'fastest_selling',
                    'text' => __('app.dashboard.insight_fastest_selling', ['name' => $fastest['name'], 'perDay' => round($fastest['units'] / $daysInPeriod, 1)]),
                ];
            }
        }

        $lowStock = $this->lowStockProducts($owner);
        foreach ($lowStock as $product) {
            $insights[] = [
                'type' => 'low_stock',
                'text' => __('app.dashboard.insight_low_stock', ['name' => $product->name, 'quantity' => $product->stock_quantity]),
            ];
        }

        $soldProductIds = collect($top)->pluck('product_id');
        $zeroSales = Product::where('owner_id', $owner->id)
            ->where('type', 'product')
            ->where('is_active', true)
            ->when($soldProductIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $soldProductIds))
            ->get();
        foreach ($zeroSales as $product) {
            $insights[] = [
                'type' => 'no_sales',
                'text' => __('app.dashboard.insight_no_sales', ['name' => $product->name]),
            ];
        }

        return $insights;
    }

    /** Sale::completed() joined down to sale_items/products, scoped to this owner/period/type — the one query shape every method above builds on. */
    private function baseQuery(Owner $owner, AnalyticsPeriod $period)
    {
        return Sale::completed()
            ->where('sales.owner_id', $owner->id)
            ->whereBetween('sales.sold_at', [$period->start, $period->end])
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('products.type', 'product');
    }
}
