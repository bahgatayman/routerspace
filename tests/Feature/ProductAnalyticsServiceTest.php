<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Workspace;
use App\Services\AnalyticsPeriod;
use App\Services\ProductAnalyticsService;
use App\Services\RevenueAnalyticsService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductAnalyticsService $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->products = app(ProductAnalyticsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking', 'sales'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);

        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);

        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room A',
            'type' => 'meeting', 'capacity' => 1, 'price_per_hour' => 50,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
    }

    private function product(Owner $owner, string $name = 'Coffee', array $overrides = []): Product
    {
        return Product::create(array_merge([
            'owner_id' => $owner->id, 'name' => $name, 'type' => 'product', 'price' => 10, 'is_active' => true,
        ], $overrides));
    }

    private function sale(Owner $owner, string $soldAt, float $total, string $status = 'completed'): Sale
    {
        return Sale::create([
            'owner_id' => $owner->id, 'status' => $status,
            'subtotal' => $total, 'total' => $total, 'sold_at' => $soldAt,
        ]);
    }

    private function item(Sale $sale, ?Product $product, int $quantity, float $unitPrice, ?string $name = null): SaleItem
    {
        return SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product?->id, 'name' => $name ?? $product?->name ?? 'Deleted product',
            'unit_price' => $unitPrice, 'quantity' => $quantity, 'line_total' => $quantity * $unitPrice,
        ]);
    }

    /** One sale, one product line — the common case. */
    private function productSale(Owner $owner, Product $product, string $soldAt, int $quantity, float $unitPrice, string $status = 'completed'): Sale
    {
        $sale = $this->sale($owner, $soldAt, $quantity * $unitPrice, $status);
        $this->item($sale, $product, $quantity, $unitPrice);

        return $sale;
    }

    private function period(string $start, string $end): AnalyticsPeriod
    {
        return AnalyticsPeriod::custom(Carbon::parse($start), Carbon::parse($end));
    }

    private function booking(Owner $owner, Room $room, string $date): Booking
    {
        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id,
            'party_size' => 1, 'booking_date' => $date, 'start_time' => '09:00', 'end_time' => '11:00',
            'price_per_hour' => 25, 'total_hours' => 2, 'total_price' => 50,
            'amount_paid' => 50, 'payment_status' => 'paid', 'status' => 'completed',
        ]);
    }

    /** A completed product sale attached to a booking — the join productSpendByRoomType() relies on. */
    private function bookingProductSale(Owner $owner, Booking $booking, Product $product, string $soldAt, int $quantity, float $unitPrice): Sale
    {
        $sale = Sale::create([
            'owner_id' => $owner->id, 'booking_id' => $booking->id, 'status' => 'completed',
            'subtotal' => $quantity * $unitPrice, 'total' => $quantity * $unitPrice, 'sold_at' => $soldAt,
        ]);
        $this->item($sale, $product, $quantity, $unitPrice);

        return $sale;
    }

    // --- summary() ---

    public function test_summary_counts_units_revenue_orders_from_completed_sales_only(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 3, 10.0); // completed, counts
        $this->productSale($owner, $coffee, '2026-09-10 11:00:00', 5, 10.0, 'open'); // open tab, excluded
        $this->productSale($owner, $coffee, '2026-09-10 12:00:00', 7, 10.0, 'cancelled'); // cancelled, excluded

        $summary = $this->products->summary($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame(3, $summary['totalUnits']);
        $this->assertEquals(30.0, $summary['productRevenue']);
        $this->assertSame(1, $summary['orderCount']);
    }

    public function test_summary_excludes_open_and_cancelled_sales(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner);
        $this->productSale($owner, $product, '2026-09-10 10:00:00', 1, 10.0, 'open');
        $this->productSale($owner, $product, '2026-09-10 10:00:00', 1, 10.0, 'cancelled');

        $summary = $this->products->summary($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame(0, $summary['totalUnits']);
        $this->assertEquals(0.0, $summary['productRevenue']);
        $this->assertNull($summary['avgOrderValue']);
    }

    public function test_summary_excludes_service_type_products(): void
    {
        $owner = $this->owner();
        $service = $this->product($owner, 'Table Booking Fee', ['type' => 'service']);
        $this->productSale($owner, $service, '2026-09-10 10:00:00', 2, 50.0);

        $summary = $this->products->summary($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame(0, $summary['totalUnits']);
        $this->assertEquals(0.0, $summary['productRevenue']);
    }

    public function test_summary_top_product_is_the_highest_revenue_product(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $water = $this->product($owner, 'Water');
        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 1, 100.0); // 100 revenue
        $this->productSale($owner, $water, '2026-09-10 10:00:00', 20, 1.0); // 20 revenue, more units

        $summary = $this->products->summary($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame('Coffee', $summary['topProduct']['name']);
    }

    public function test_summary_avg_order_value_divides_product_revenue_by_distinct_order_count(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner);
        $this->productSale($owner, $product, '2026-09-10 10:00:00', 1, 100.0);
        $this->productSale($owner, $product, '2026-09-11 10:00:00', 1, 50.0);

        $summary = $this->products->summary($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame(2, $summary['orderCount']);
        $this->assertEquals(75.0, $summary['avgOrderValue']);
    }

    public function test_summary_is_null_average_order_value_when_no_product_orders(): void
    {
        $owner = $this->owner();

        $summary = $this->products->summary($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertNull($summary['avgOrderValue']);
        $this->assertSame(0, $summary['orderCount']);
    }

    public function test_summary_low_stock_count_matches_product_scope_low_stock(): void
    {
        $owner = $this->owner();
        $this->product($owner, 'Low One', ['track_stock' => true, 'stock_quantity' => 2, 'low_stock_threshold' => 5]);
        $this->product($owner, 'Plenty', ['track_stock' => true, 'stock_quantity' => 50, 'low_stock_threshold' => 5]);

        $summary = $this->products->summary($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame(Product::where('owner_id', $owner->id)->lowStock()->count(), $summary['lowStockCount']);
        $this->assertSame(1, $summary['lowStockCount']);
    }

    // --- dailySeries() ---

    public function test_daily_series_zero_fills_days_with_no_sales(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner);
        $this->productSale($owner, $product, '2026-09-10 10:00:00', 3, 10.0);

        $series = $this->products->dailySeries($owner, $this->period('2026-09-08', '2026-09-12'));

        $this->assertCount(5, $series);
        $this->assertSame(['units' => 0, 'revenue' => 0.0, 'orders' => 0], $series['2026-09-08']);
        $this->assertSame(['units' => 3, 'revenue' => 30.0, 'orders' => 1], $series['2026-09-10']);
        $this->assertSame(['units' => 0, 'revenue' => 0.0, 'orders' => 0], $series['2026-09-12']);
    }

    public function test_daily_series_buckets_multiple_products_on_the_same_day_correctly(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $water = $this->product($owner, 'Water');
        $this->productSale($owner, $coffee, '2026-09-10 09:00:00', 2, 10.0);
        $this->productSale($owner, $water, '2026-09-10 15:00:00', 4, 5.0);

        $series = $this->products->dailySeries($owner, $this->period('2026-09-10', '2026-09-10'));

        $this->assertSame(6, $series['2026-09-10']['units']);
        $this->assertEquals(40.0, $series['2026-09-10']['revenue']);
        $this->assertSame(2, $series['2026-09-10']['orders']);
    }

    // --- topProducts() ---

    public function test_top_products_ranks_by_revenue_desc_by_default(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $water = $this->product($owner, 'Water');
        $this->productSale($owner, $water, '2026-09-10 10:00:00', 1, 5.0);
        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 1, 100.0);

        $top = $this->products->topProducts($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame('Coffee', $top[0]['name']);
        $this->assertSame('Water', $top[1]['name']);
    }

    public function test_top_products_includes_order_count_per_product(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner);
        $this->productSale($owner, $product, '2026-09-10 10:00:00', 1, 10.0);
        $this->productSale($owner, $product, '2026-09-11 10:00:00', 1, 10.0);

        $top = $this->products->topProducts($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame(2, $top[0]['orders']);
        $this->assertSame(2, $top[0]['units']);
    }

    public function test_top_products_handles_multiple_products_with_overlapping_sales(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $water = $this->product($owner, 'Water');
        $sale = $this->sale($owner, '2026-09-10 10:00:00', 150.0);
        $this->item($sale, $coffee, 10, 10.0);
        $this->item($sale, $water, 10, 5.0);

        $top = collect($this->products->topProducts($owner, $this->period('2026-09-01', '2026-09-30')))->keyBy('name');

        $this->assertEquals(100.0, $top['Coffee']['revenue']);
        $this->assertEquals(50.0, $top['Water']['revenue']);
        $this->assertSame(1, $top['Coffee']['orders']);
    }

    // --- lowStockProducts() ---

    public function test_low_stock_products_matches_product_scope_low_stock_exactly(): void
    {
        $owner = $this->owner();
        $low = $this->product($owner, 'Low', ['track_stock' => true, 'stock_quantity' => 1, 'low_stock_threshold' => 5]);
        $this->product($owner, 'Out', ['track_stock' => true, 'stock_quantity' => 0, 'low_stock_threshold' => 5]);
        $this->product($owner, 'Fine', ['track_stock' => true, 'stock_quantity' => 50, 'low_stock_threshold' => 5]);

        $result = $this->products->lowStockProducts($owner);

        $this->assertCount(1, $result);
        $this->assertSame($low->id, $result->first()->id);
    }

    // --- insights() ---

    public function test_insights_identifies_best_seller_by_units(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $water = $this->product($owner, 'Water');
        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 50, 1.0);
        $this->productSale($owner, $water, '2026-09-10 10:00:00', 5, 1.0);

        $insights = $this->products->insights($owner, $this->period('2026-09-01', '2026-09-30'));

        $bestSeller = collect($insights)->firstWhere('type', 'best_seller');
        $this->assertNotNull($bestSeller);
        $this->assertStringContainsString('Coffee', $bestSeller['text']);
    }

    public function test_insights_identifies_highest_revenue_product(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $water = $this->product($owner, 'Water');
        // Water sells the most units but Coffee earns more revenue per unit
        // — exactly the "best-seller isn't always highest-revenue" case.
        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 1, 200.0);
        $this->productSale($owner, $water, '2026-09-10 10:00:00', 100, 1.0);

        $insights = $this->products->insights($owner, $this->period('2026-09-01', '2026-09-30'));

        $bestSeller = collect($insights)->firstWhere('type', 'best_seller');
        $highest = collect($insights)->firstWhere('type', 'highest_revenue');
        $this->assertStringContainsString('Water', $bestSeller['text']);
        $this->assertNotNull($highest);
        $this->assertStringContainsString('Coffee', $highest['text']);
    }

    public function test_insights_identifies_biggest_increase_vs_previous_period(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner, 'Coffee');
        $this->productSale($owner, $product, '2026-08-10 10:00:00', 1, 10.0); // previous period
        $this->productSale($owner, $product, '2026-09-10 10:00:00', 20, 10.0); // current period, big jump

        $insights = $this->products->insights($owner, $this->period('2026-09-01', '2026-09-30'));

        $increase = collect($insights)->firstWhere('type', 'biggest_increase');
        $this->assertNotNull($increase);
        $this->assertStringContainsString('Coffee', $increase['text']);
    }

    public function test_insights_lists_zero_sales_products(): void
    {
        $owner = $this->owner();
        $this->product($owner, 'Never Sold');
        $sold = $this->product($owner, 'Sold');
        $this->productSale($owner, $sold, '2026-09-10 10:00:00', 1, 10.0);

        $insights = $this->products->insights($owner, $this->period('2026-09-01', '2026-09-30'));

        $noSales = collect($insights)->firstWhere('type', 'no_sales');
        $this->assertNotNull($noSales);
        $this->assertStringContainsString('Never Sold', $noSales['text']);
    }

    public function test_insights_lists_low_stock_products(): void
    {
        $owner = $this->owner();
        $this->product($owner, 'Running Low', ['track_stock' => true, 'stock_quantity' => 1, 'low_stock_threshold' => 5]);

        $insights = $this->products->insights($owner, $this->period('2026-09-01', '2026-09-30'));

        $lowStock = collect($insights)->firstWhere('type', 'low_stock');
        $this->assertNotNull($lowStock);
        $this->assertStringContainsString('Running Low', $lowStock['text']);
    }

    public function test_insights_identifies_fastest_selling_product(): void
    {
        $owner = $this->owner();
        $fast = $this->product($owner, 'Coffee');
        $slow = $this->product($owner, 'Water');
        // 30 units over a 3-day period (10/day) vs 3 units over the same period (1/day).
        $this->productSale($owner, $fast, '2026-09-10 10:00:00', 30, 1.0);
        $this->productSale($owner, $slow, '2026-09-10 10:00:00', 3, 1.0);

        $insights = $this->products->insights($owner, $this->period('2026-09-10', '2026-09-12'));

        $fastest = collect($insights)->firstWhere('type', 'fastest_selling');
        $this->assertNotNull($fastest);
        $this->assertStringContainsString('Coffee', $fastest['text']);
    }

    public function test_insights_identifies_declining_sales_vs_previous_period(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner, 'Coffee');
        $this->productSale($owner, $product, '2026-08-10 10:00:00', 20, 10.0); // previous period
        $this->productSale($owner, $product, '2026-09-10 10:00:00', 1, 10.0); // current period, big drop

        $insights = $this->products->insights($owner, $this->period('2026-09-01', '2026-09-30'));

        $declining = collect($insights)->firstWhere('type', 'declining_sales');
        $this->assertNotNull($declining);
        $this->assertStringContainsString('Coffee', $declining['text']);
    }

    // --- productSpendByRoomType() ---

    public function test_product_spend_by_room_type_groups_by_room_type_and_averages_per_booking(): void
    {
        $owner = $this->owner();
        $meetingRoom = $this->room($owner);
        $product = $this->product($owner, 'Coffee');

        // 3 meeting-room bookings, product revenue 30/10/20 => avg 20/booking.
        foreach ([30.0, 10.0, 20.0] as $amount) {
            $booking = $this->booking($owner, $meetingRoom, '2026-09-10');
            $this->bookingProductSale($owner, $booking, $product, '2026-09-10 10:00:00', 1, $amount);
        }

        $result = $this->products->productSpendByRoomType($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertCount(1, $result);
        $this->assertSame('meeting', $result[0]['roomType']);
        $this->assertSame(3, $result[0]['bookingCount']);
        $this->assertSame(20.0, $result[0]['productRevenuePerBooking']);
    }

    public function test_product_spend_by_room_type_drops_types_below_minimum_booking_count(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $product = $this->product($owner, 'Coffee');

        // Only 2 bookings — below MIN_BOOKINGS_FOR_ROOM_TYPE_COMPARISON (3).
        foreach ([10.0, 10.0] as $amount) {
            $booking = $this->booking($owner, $room, '2026-09-10');
            $this->bookingProductSale($owner, $booking, $product, '2026-09-10 10:00:00', 1, $amount);
        }

        $result = $this->products->productSpendByRoomType($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame([], $result);
    }

    public function test_product_spend_by_room_type_excludes_sales_with_no_booking(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner, 'Coffee');

        // A standalone walk-in sale with no booking_id — never counted here.
        $this->productSale($owner, $product, '2026-09-10 10:00:00', 1, 10.0);

        $result = $this->products->productSpendByRoomType($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame([], $result);
    }

    // --- empty period ---

    public function test_empty_period_returns_zeroed_summary_and_empty_collections(): void
    {
        $owner = $this->owner();

        $summary = $this->products->summary($owner, $this->period('2026-09-01', '2026-09-30'));
        $top = $this->products->topProducts($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame(0, $summary['totalUnits']);
        $this->assertEquals(0.0, $summary['productRevenue']);
        $this->assertNull($summary['topProduct']);
        $this->assertSame([], $top);
    }

    // --- cascade fix: cancelling a booking cancels its sale ---

    public function test_cancelled_sale_items_are_excluded_from_every_metric(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        $product = $this->product($owner, 'Coffee');
        $member = $this->member($owner);

        $booking = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'party_size' => 1, 'booking_date' => '2026-09-10', 'start_time' => '10:00', 'end_time' => '12:00',
            'price_per_hour' => 50, 'total_hours' => 2, 'total_price' => 100, 'status' => 'confirmed',
        ]);
        $sale = Sale::create([
            'owner_id' => $owner->id, 'booking_id' => $booking->id, 'status' => 'completed',
            'subtotal' => 30, 'total' => 30, 'sold_at' => now(),
        ]);
        $this->item($sale, $product, 3, 10.0);

        $period = $this->period('2026-09-01', '2026-09-30');
        $this->assertEquals(30.0, $this->products->summary($owner, $period)['productRevenue']);

        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/status", ['status' => 'cancelled'])
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertEquals(0.0, $this->products->summary($owner, $period)['productRevenue']);
    }

    // --- cross-check against RevenueAnalyticsService ---

    public function test_cross_check_product_revenue_matches_revenue_by_product_when_no_products_are_deleted(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $water = $this->product($owner, 'Water');
        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 3, 10.0);
        $this->productSale($owner, $water, '2026-09-11 10:00:00', 2, 5.0);

        $period = $this->period('2026-09-01', '2026-09-30');
        $revenue = app(RevenueAnalyticsService::class);

        $byProductTotal = collect($revenue->revenueByProduct($owner, $period))->sum('revenue');
        $summary = $this->products->summary($owner, $period);

        $this->assertEquals($byProductTotal, $summary['productRevenue']);
    }

    public function test_deleted_product_sale_items_diverge_from_revenue_by_product_by_design(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner, 'Coffee');
        $sale = $this->sale($owner, '2026-09-10 10:00:00', 10.0);
        $this->item($sale, $product, 1, 10.0);
        $product->delete(); // sale_items.product_id becomes null (nullOnDelete)

        $period = $this->period('2026-09-01', '2026-09-30');
        $revenue = app(RevenueAnalyticsService::class);

        // RevenueAnalyticsService keeps the orphaned line (overall revenue
        // completeness); ProductAnalyticsService excludes it (no catalog
        // product to attribute a per-product breakdown to) — documented
        // divergence, not a bug.
        $byProductTotal = collect($revenue->revenueByProduct($owner, $period))->sum('revenue');
        $summary = $this->products->summary($owner, $period);

        $this->assertEquals(10.0, $byProductTotal);
        $this->assertEquals(0.0, $summary['productRevenue']);
    }

    // --- performance ---

    public function test_large_product_catalog_does_not_change_query_count(): void
    {
        $small = $this->owner();
        for ($i = 0; $i < 2; $i++) {
            $this->productSale($small, $this->product($small, "Product {$i}"), '2026-09-10 10:00:00', 1, 10.0);
        }

        $large = $this->owner();
        for ($i = 0; $i < 20; $i++) {
            $this->productSale($large, $this->product($large, "Product {$i}"), '2026-09-10 10:00:00', 1, 10.0);
        }

        $period = $this->period('2026-09-01', '2026-09-30');

        DB::enableQueryLog();
        $this->products->summary($small, $period);
        $this->products->dailySeries($small, $period);
        $this->products->lowStockProducts($small);
        $this->products->insights($small, $period);
        $smallCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->products->summary($large, $period);
        $this->products->dailySeries($large, $period);
        $this->products->lowStockProducts($large);
        $this->products->insights($large, $period);
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($smallCount, $largeCount, 'Product Analytics query count must not grow with catalog size.');
    }

    // --- productsAndServicesBreakdown() — the dashboard's combined chart ---

    public function test_products_and_services_breakdown_includes_both_types_with_units(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $printing = $this->product($owner, 'Printing', ['type' => 'service']);

        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 3, 10.0);
        $this->productSale($owner, $printing, '2026-09-10 11:00:00', 2, 5.0);

        $rows = collect($this->products->productsAndServicesBreakdown($owner, $this->period('2026-09-01', '2026-09-30')))->keyBy('name');

        $this->assertSame('product', $rows['Coffee']['type']);
        $this->assertSame(3, $rows['Coffee']['units']);
        $this->assertSame('service', $rows['Printing']['type']);
        $this->assertSame(2, $rows['Printing']['units']);
    }

    public function test_products_and_services_breakdown_excludes_cancelled_sales(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 5, 10.0, 'cancelled');

        $rows = $this->products->productsAndServicesBreakdown($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertSame([], $rows);
    }

    public function test_products_and_services_breakdown_never_double_counts_a_sale_item(): void
    {
        $owner = $this->owner();
        $coffee = $this->product($owner, 'Coffee');
        $this->productSale($owner, $coffee, '2026-09-10 10:00:00', 4, 10.0);

        $rows = $this->products->productsAndServicesBreakdown($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['units']);
    }

    public function test_products_and_services_breakdown_is_scoped_to_the_owner(): void
    {
        $owner = $this->owner();
        $otherOwner = $this->owner();
        $this->productSale($owner, $this->product($owner, 'Coffee'), '2026-09-10 10:00:00', 1, 10.0);
        $this->productSale($otherOwner, $this->product($otherOwner, 'Tea'), '2026-09-10 10:00:00', 1, 10.0);

        $rows = $this->products->productsAndServicesBreakdown($owner, $this->period('2026-09-01', '2026-09-30'));

        $this->assertCount(1, $rows);
        $this->assertSame('Coffee', $rows[0]['name']);
    }
}
