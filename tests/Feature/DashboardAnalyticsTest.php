<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Notification;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Workspace;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2: the redesigned owner dashboard — controller wiring of the Phase 1
 * analytics services, period switching, tenant isolation, permission gates,
 * and query-count regression coverage (the whole point of
 * OccupancyAnalyticsService/BookingAnalyticsService's batched-query design
 * is that the dashboard's cost doesn't grow with room/booking count).
 */
class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(array $features = ['workspace', 'booking', 'sales']): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => $features, 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);

        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);

        foreach ($features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $name = 'Room', string $type = 'meeting'): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name,
            'type' => $type, 'capacity' => 4, 'price_per_hour' => 50,
        ]);
    }

    private function member(Owner $owner, ?string $createdAt = null): HotspotUser
    {
        $member = HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);

        if ($createdAt) {
            HotspotUser::where('id', $member->id)->update(['created_at' => $createdAt]);
        }

        return $member->fresh();
    }

    private function booking(
        Owner $owner,
        Room $room,
        string $date,
        string $start = '09:00',
        string $end = '11:00',
        float $totalPrice = 100.0,
        string $status = 'completed',
    ): Booking {
        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id,
            'hotspot_user_id' => $this->member($owner)->id,
            'party_size' => 1,
            'booking_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'price_per_hour' => $totalPrice / 2, 'total_hours' => 2, 'total_price' => $totalPrice,
            'amount_paid' => $totalPrice, 'payment_status' => 'paid',
            'status' => $status,
        ]);
    }

    private function sale(Owner $owner, string $soldAt, float $total): Sale
    {
        return Sale::create([
            'owner_id' => $owner->id, 'status' => 'completed',
            'subtotal' => $total, 'total' => $total, 'sold_at' => $soldAt,
        ]);
    }

    /** A completed sale with one product line — what the Products section actually reads. */
    private function productSale(Owner $owner, Product $product, string $soldAt, int $quantity, float $unitPrice): Sale
    {
        $sale = $this->sale($owner, $soldAt, $quantity * $unitPrice);
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'name' => $product->name,
            'unit_price' => $unitPrice, 'quantity' => $quantity, 'line_total' => $quantity * $unitPrice,
        ]);

        return $sale;
    }

    private function product(Owner $owner, string $name = 'Coffee'): Product
    {
        return Product::create(['owner_id' => $owner->id, 'name' => $name, 'type' => 'product', 'price' => 10, 'is_active' => true]);
    }

    // --- Basic rendering ---

    /** All page props as JSON — for "this value appears / never appears anywhere on the page" checks. */
    private function propsJson($response): string
    {
        return json_encode($response->inertiaProps(), JSON_UNESCAPED_UNICODE);
    }

    public function test_dashboard_renders_the_new_kpis_for_a_fully_featured_owner(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-27', totalPrice: 150.0);
        $this->sale($owner, '2026-08-27 09:30:00', 25.0);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertSame('Dashboard/Index', $response->inertiaPage()['component']);
        $this->assertTrue($response->inertiaProps('showRevenue'));
        $this->assertEquals(175.0, $response->inertiaProps('revenue.today')); // 150 booking + 25 sale
        $this->assertTrue($response->inertiaProps('showWorkspace'));
        $this->assertNotNull($response->inertiaProps('occupancy'));
        $this->assertNotNull($response->inertiaProps('needsAttention'));
        $this->assertIsArray($response->inertiaProps('booking.todaysSchedule'));
        $this->assertIsArray($response->inertiaProps('roomUtilization'));
    }

    public function test_dashboard_renders_the_products_section_for_a_sales_feature_owner(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner(['sales']);
        $product = $this->product($owner);
        $this->productSale($owner, $product, '2026-08-27 09:00:00', 3, 10.0);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertTrue($response->inertiaProps('showProducts'));
        $this->assertSame('Coffee', $response->inertiaProps('products.summary.topProduct.name'));
        $this->assertTrue($response->inertiaProps('products.hasSales'));
        $this->assertSame('Coffee', $response->inertiaProps('products.top.0.name'));
    }

    public function test_dashboard_skips_the_products_section_without_the_sales_feature(): void
    {
        $owner = $this->owner(['workspace', 'booking']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertFalse($response->inertiaProps('showProducts'));
        $this->assertNull($response->inertiaProps('products'));
    }

    public function test_products_section_renders_empty_state_with_no_sales_in_period(): void
    {
        $owner = $this->owner(['sales']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertTrue($response->inertiaProps('showProducts'));
        $this->assertFalse($response->inertiaProps('products.hasSales'));
    }

    public function test_dashboard_hides_smart_insights_when_nothing_qualifies(): void
    {
        $owner = $this->owner(['workspace', 'booking']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertSame([], $response->inertiaProps('smartInsights'));
    }

    public function test_dashboard_shows_smart_insights_with_a_revenue_change_sentence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner(['sales']);
        $product = $this->product($owner);
        $this->productSale($owner, $product, '2026-08-20 10:00:00', 1, 100.0); // current 30d window
        $this->productSale($owner, $product, '2026-07-10 10:00:00', 1, 50.0); // previous 30d window

        $response = $this->actingAs($owner, 'owner')->get('/dashboard?period=30d');

        $response->assertOk();
        $this->assertNotEmpty($response->inertiaProps('smartInsights'));
        $this->assertSame($response->inertiaProps('revenue.changeSentence'), $response->inertiaProps('smartInsights.0'));
    }

    public function test_needs_attention_reflects_unread_notification_count(): void
    {
        $owner = $this->owner();
        Notification::create(['owner_id' => $owner->id, 'type' => 'general', 'level' => 'warning', 'title' => 'Alert one']);
        // Already read — must not inflate the count, nor appear in the list.
        Notification::create(['owner_id' => $owner->id, 'type' => 'general', 'level' => 'info', 'title' => 'Alert two', 'read_at' => now()]);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertSame(1, $response->inertiaProps('needsAttention.count'));
        $this->assertSame(['Alert one'], array_column($response->inertiaProps('needsAttention.items'), 'title'));
    }

    public function test_needs_attention_shows_all_caught_up_when_nothing_is_unread(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertSame(0, $response->inertiaProps('needsAttention.count'));
        $this->assertSame([], $response->inertiaProps('needsAttention.items'));
    }

    // --- Period switching ---

    public function test_period_selector_changes_the_revenue_trend_and_new_customers_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        // lastDays(7) from "now" (08-27) covers 08-21..08-27 inclusive, so
        // 08-25 falls inside the 7d window but is not "today".
        $this->booking($owner, $room, '2026-08-25', totalPrice: 60.0);
        $this->member($owner, '2026-08-25 10:00:00');

        $today = $this->actingAs($owner, 'owner')->get('/dashboard?period=today');
        $today->assertOk();
        // that day isn't in a 1-day trend window
        $this->assertNotContains('25 Aug', array_column($today->inertiaProps('revenue.trend'), 'label'));

        $week = $this->actingAs($owner, 'owner')->get('/dashboard?period=7d');
        $week->assertOk();
        $this->assertContains('25 Aug', array_column($week->inertiaProps('revenue.trend'), 'label'));
    }

    public function test_new_customers_count_reflects_the_selected_period(): void
    {
        // lastDays(7) from "now" (08-27) covers 08-21..08-27 — 08-01 falls
        // outside it; lastDays(30) covers 07-29..08-27 — 08-01 falls inside.
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $this->member($owner, '2026-08-01 10:00:00');

        $week = $this->actingAs($owner, 'owner')->get('/dashboard?period=7d');
        $week->assertOk();

        $month = $this->actingAs($owner, 'owner')->get('/dashboard?period=30d');
        $month->assertOk();

        $this->assertSame(0, $week->inertiaProps('customers.newCustomers'));
        $this->assertSame(1, $month->inertiaProps('customers.newCustomers'));
    }

    public function test_an_unrecognized_period_value_falls_back_to_today_without_error(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'owner')->get('/dashboard?period=bogus');

        $response->assertOk();
        $this->assertSame('today', $response->inertiaProps('periodKey'));
    }

    // --- Owner isolation ---

    public function test_dashboard_never_leaks_another_owners_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $other = $this->owner();

        $room = $this->room($owner, 'My Room');
        $otherRoom = $this->room($other, 'Secret Room');
        $this->booking($owner, $room, '2026-08-27', totalPrice: 100.0);
        $this->booking($other, $otherRoom, '2026-08-27', totalPrice: 999.0);
        Notification::create(['owner_id' => $other->id, 'type' => 'general', 'level' => 'warning', 'title' => 'Other owners alert']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $json = $this->propsJson($response);
        $this->assertStringContainsString('My Room', $json);
        $this->assertStringNotContainsString('Secret Room', $json);
        $this->assertStringNotContainsString('999', $json);
        $this->assertStringNotContainsString('Other owners alert', $json);
        $response->assertDontSee('Secret Room');
        $response->assertDontSee('Other owners alert');
    }

    // --- Feature/permission gating ---

    public function test_hotspot_only_owner_does_not_see_revenue_or_occupancy_sections(): void
    {
        $owner = $this->owner(['hotspot']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertFalse($response->inertiaProps('showRevenue'));
        $this->assertNull($response->inertiaProps('revenue'));
        $this->assertFalse($response->inertiaProps('showWorkspace'));
        $this->assertNull($response->inertiaProps('occupancy'));
        $this->assertNull($response->inertiaProps('booking')); // no today's schedule
        // Needs Attention is universal — shown regardless of feature mix.
        $this->assertNotNull($response->inertiaProps('needsAttention'));
    }

    public function test_booking_only_owner_without_workspace_feature_sees_bookings_but_not_occupancy(): void
    {
        $owner = $this->owner(['booking']);
        $room = $this->room($owner);
        $this->booking($owner, $room, now()->toDateString());

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $this->assertNotNull($response->inertiaProps('booking.todayBookings'));
        $this->assertIsArray($response->inertiaProps('booking.peakHoursGrid'));
        $this->assertFalse($response->inertiaProps('showWorkspace'));
        $this->assertNull($response->inertiaProps('occupancy'));
        $this->assertNull($response->inertiaProps('roomUtilization'));
    }

    // --- No N+1: cost must not scale with room/booking count ---

    public function test_dashboard_query_count_does_not_grow_with_room_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));

        $small = $this->owner();
        for ($i = 0; $i < 2; $i++) {
            $room = $this->room($small, "Room {$i}");
            $this->booking($small, $room, '2026-08-27', totalPrice: 50.0);
            $this->booking($small, $room, '2026-08-25', totalPrice: 50.0, status: 'completed');
        }

        $large = $this->owner();
        for ($i = 0; $i < 12; $i++) {
            $room = $this->room($large, "Room {$i}");
            $this->booking($large, $room, '2026-08-27', totalPrice: 50.0);
            $this->booking($large, $room, '2026-08-25', totalPrice: 50.0, status: 'completed');
        }

        DB::enableQueryLog();
        $this->actingAs($small, 'owner')->get('/dashboard?period=30d')->assertOk();
        $smallCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAs($large, 'owner')->get('/dashboard?period=30d')->assertOk();
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $smallCount,
            $largeCount,
            "Query count grew from {$smallCount} (2 rooms) to {$largeCount} (12 rooms) — likely a per-room query loop.",
        );
    }
}
