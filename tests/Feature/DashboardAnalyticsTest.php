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

    public function test_dashboard_renders_the_new_kpis_for_a_fully_featured_owner(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-27', totalPrice: 150.0);
        $this->sale($owner, '2026-08-27 09:30:00', 25.0);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.revenue_today'));
        $response->assertSee('ج.م 175.00'); // 150 booking + 25 sale
        $response->assertSee(__('app.dashboard.current_occupancy'));
        $response->assertSee(__('app.dashboard.needs_attention'));
        $response->assertSee(__('app.dashboard.todays_schedule'));
        $response->assertSee(__('app.dashboard.room_utilization_details'));
    }

    public function test_dashboard_renders_the_products_section_for_a_sales_feature_owner(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner(['sales']);
        $product = $this->product($owner);
        $this->productSale($owner, $product, '2026-08-27 09:00:00', 3, 10.0);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.product_analytics'));
        $response->assertSee('Coffee');
        $response->assertSee(__('app.dashboard.top_selling_product'));
        $response->assertSee(__('app.dashboard.product_sales_trend'));
    }

    public function test_dashboard_skips_the_products_section_without_the_sales_feature(): void
    {
        $owner = $this->owner(['workspace', 'booking']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(__('app.dashboard.product_analytics'));
    }

    public function test_products_section_renders_empty_state_with_no_sales_in_period(): void
    {
        $owner = $this->owner(['sales']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.product_analytics'));
        $response->assertSee(__('app.dashboard.no_product_sales_for_period'));
    }

    public function test_needs_attention_reflects_unread_notification_count(): void
    {
        $owner = $this->owner();
        Notification::create(['owner_id' => $owner->id, 'type' => 'general', 'level' => 'warning', 'title' => 'Alert one']);
        // Already read — must not inflate the count. (Its title can still
        // legitimately appear in the header bell's separate "recent" list,
        // which shows read+unread — so this only checks the KPI count, not
        // page-wide text absence.)
        Notification::create(['owner_id' => $owner->id, 'type' => 'general', 'level' => 'info', 'title' => 'Alert two', 'read_at' => now()]);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Alert one');
        $this->assertSame(1, $this->extractNeedsAttentionCount($response->getContent()));
    }

    private function extractNeedsAttentionCount(string $html): int
    {
        $label = preg_quote(__('app.dashboard.needs_attention'), '/');
        preg_match("/{$label}<\/p>\\s*<p[^>]*>(\\d+)<\/p>/", $html, $matches);

        $this->assertNotEmpty($matches, 'Could not locate the "Needs Attention" figure in the dashboard HTML.');

        return (int) $matches[1];
    }

    public function test_needs_attention_shows_all_caught_up_when_nothing_is_unread(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.all_caught_up'));
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
        $today->assertDontSee('25 Aug'); // that day isn't in a 1-day trend window

        $week = $this->actingAs($owner, 'owner')->get('/dashboard?period=7d');
        $week->assertOk();
        $week->assertSee('25 Aug');
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

        $this->assertSame(0, $this->extractNewCustomers($week->getContent()));
        $this->assertSame(1, $this->extractNewCustomers($month->getContent()));
    }

    private function extractNewCustomers(string $html): int
    {
        $label = preg_quote(__('app.dashboard.new_customers'), '/');
        preg_match("/{$label}<\/p>\\s*<p[^>]*>(\\d+)<\/p>/", $html, $matches);

        $this->assertNotEmpty($matches, 'Could not locate the "New Customers" figure in the dashboard HTML.');

        return (int) $matches[1];
    }

    public function test_an_unrecognized_period_value_falls_back_to_today_without_error(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'owner')->get('/dashboard?period=bogus');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.period_today'));
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
        $response->assertSee('My Room');
        $response->assertDontSee('Secret Room');
        $response->assertDontSee('999.00');
        $response->assertDontSee('Other owners alert');
    }

    // --- Feature/permission gating ---

    public function test_hotspot_only_owner_does_not_see_revenue_or_occupancy_sections(): void
    {
        $owner = $this->owner(['hotspot']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(__('app.dashboard.revenue_today'));
        $response->assertDontSee(__('app.dashboard.current_occupancy'));
        $response->assertDontSee(__('app.dashboard.todays_schedule'));
        // Needs Attention is universal — shown regardless of feature mix.
        $response->assertSee(__('app.dashboard.needs_attention'));
    }

    public function test_booking_only_owner_without_workspace_feature_sees_bookings_but_not_occupancy(): void
    {
        $owner = $this->owner(['booking']);
        $room = $this->room($owner);
        $this->booking($owner, $room, now()->toDateString());

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.label.today_bookings'));
        $response->assertSee(__('app.dashboard.peak_hours'));
        $response->assertDontSee(__('app.dashboard.current_occupancy'));
        $response->assertDontSee(__('app.dashboard.room_utilization_details'));
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
