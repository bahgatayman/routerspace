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
use App\Services\HotspotSyncService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestableHotspotSyncService;
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

    private function sale(Owner $owner, string $soldAt, float $total, string $status = 'completed'): Sale
    {
        return Sale::create([
            'owner_id' => $owner->id, 'status' => $status,
            'subtotal' => $total, 'total' => $total, 'sold_at' => $soldAt,
        ]);
    }

    /** A sale (completed by default) with one product line — what the Products section actually reads. */
    private function productSale(Owner $owner, Product $product, string $soldAt, int $quantity, float $unitPrice, string $status = 'completed'): Sale
    {
        $sale = $this->sale($owner, $soldAt, $quantity * $unitPrice, $status);
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
        $response->assertSee('EGP 175.00'); // 150 booking + 25 sale
        $response->assertSee(__('app.dashboard.current_occupancy'));
        $response->assertSee(__('app.dashboard.needs_attention'));
        $response->assertSee(__('app.dashboard.todays_schedule'));
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

    public function test_dashboard_hides_smart_insights_when_nothing_qualifies(): void
    {
        $owner = $this->owner(['workspace', 'booking']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(__('app.dashboard.smart_insights'));
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
        $response->assertSee(__('app.dashboard.smart_insights'));
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

    // --- Interactive charts (admin.partials.chart reuse) ---

    public function test_fully_featured_owner_sees_the_new_chart_titles_and_loads_chartjs_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-27');
        $product = $this->product($owner);
        $this->productSale($owner, $product, '2026-08-27 09:00:00', 1, 10);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.revenue_trend'));
        $response->assertSee(__('app.dashboard.bookings_activity'));
        $response->assertSee(__('app.dashboard.booking_status'));

        // Products' own bespoke charts and the shared admin.partials.chart component both
        // want Chart.js on this exact page — must still resolve to exactly one CDN load.
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'chart.umd.min.js'), 'Chart.js CDN tag loaded more than once on the same page.');
    }

    public function test_booking_status_chart_omits_zero_count_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner(['booking']);
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-27', status: 'pending');
        $this->booking($owner, $room, '2026-08-27', status: 'completed');

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Pending');
        $response->assertSee('Completed');
        $response->assertDontSee('Cancelled');
        $response->assertDontSee('No Show');
    }

    public function test_router_status_shows_connected_when_no_router_failure(): void
    {
        $owner = $this->owner(['hotspot']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.router_status'));
        $response->assertSee(__('app.dashboard.router_connected'));
        $response->assertDontSee(__('app.dashboard.router_unreachable'));
    }

    public function test_router_status_shows_unreachable_when_the_configured_router_fails_to_connect(): void
    {
        $owner = $this->owner(['hotspot']);
        $owner->forceFill(['mikrotik_host' => '10.0.0.1', 'mikrotik_port' => 8728, 'mikrotik_username' => 'admin', 'mikrotik_password' => 'secret'])->save();

        $fake = new TestableHotspotSyncService;
        $fake->fake->failConnect = true;
        $this->app->instance(HotspotSyncService::class, $fake);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.router_unreachable'));
        $response->assertDontSee(__('app.dashboard.router_connected'));
    }

    // --- Dashboard decluttering: Room Utilization Details / Needs Attention list / Your Features removed ---

    public function test_removed_sections_no_longer_render_on_the_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-27');
        Notification::create(['owner_id' => $owner->id, 'type' => 'general', 'level' => 'warning', 'title' => 'Alert one']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');
        $html = $response->getContent();

        $response->assertOk();
        // Unique to the removed Room Utilization Details table — Room Performance
        // (kept) uses different keys (room_performance/room_utilization).
        $response->assertDontSee(__('app.dashboard.room_utilization_details'));
        // Unique to the removed "Your Features" section.
        $response->assertDontSee(__('app.label.your_features'));
        // "Needs Attention" is shared with the top KPI tile (kept) — it must
        // appear exactly once now, not twice (KPI tile + the removed card's
        // own heading).
        $this->assertSame(1, substr_count($html, __('app.dashboard.needs_attention')));
    }

    public function test_notifications_and_features_still_work_outside_the_dashboard(): void
    {
        $owner = $this->owner();
        $notification = Notification::create(['owner_id' => $owner->id, 'type' => 'general', 'level' => 'warning', 'title' => 'Alert one']);

        // Removing the dashboard's own list must not touch the Notification
        // feature's routes/records elsewhere in the app.
        $this->actingAs($owner, 'owner')->get(route('notifications.index'))->assertOk()->assertSee('Alert one');
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'owner_id' => $owner->id]);

        // Feature pages themselves (workspace/booking/sales) are untouched —
        // the removed section only ever displayed them, never gated them.
        $this->assertTrue($owner->hasFeature('workspace'));
        $this->assertTrue($owner->hasFeature('booking'));
        $this->assertTrue($owner->hasFeature('sales'));
    }

    // --- Products & Services chart ---

    public function test_products_services_chart_renders_alongside_booking_status_for_a_fully_featured_owner(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $product = $this->product($owner, 'Coffee');
        $this->productSale($owner, $product, '2026-08-27 10:00:00', 3, 10);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('app.dashboard.products_services'));
        $response->assertSee(__('app.dashboard.booking_status'));
        $response->assertSee('Coffee');
    }

    public function test_products_services_chart_is_hidden_without_the_sales_feature(): void
    {
        $owner = $this->owner(['booking']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(__('app.dashboard.products_services'));
    }

    public function test_products_services_chart_excludes_cancelled_sales(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner(['sales']);
        $product = $this->product($owner, 'Coffee');
        $this->productSale($owner, $product, '2026-08-27 10:00:00', 5, 10, 'cancelled');

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        // The chart exists but with no data — the shared component's own empty state, not "Coffee".
        $response->assertSee(__('app.dashboard.products_services'));
        $response->assertDontSee('Coffee');
    }

    // --- Hero layout: revenue trend chart + Revenue Today, Quick Links removed ---

    public function test_revenue_hero_shows_the_chart_and_the_revenue_today_card_together(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-27', totalPrice: 150.0);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee(__('app.dashboard.revenue_trend'));
        $response->assertSee(__('app.dashboard.revenue_today'));
        $response->assertSee('EGP 150.00');
        // Exactly one chart instance — never duplicated by the layout move.
        $this->assertSame(1, substr_count($html, 'data-ls-chart="ls-revenue-trend"'));
    }

    public function test_quick_links_no_longer_render_on_the_dashboard(): void
    {
        $owner = $this->owner(['hotspot']);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(__('app.label.quick_links'));
        $response->assertDontSee(__('app.btn.add_user'));
        // The stats strip itself (Total Users / Online Now / Router Status) is unaffected.
        $response->assertSee(__('app.dashboard.router_status'));
    }

    public function test_users_create_and_speed_profile_routes_still_work_outside_the_dashboard(): void
    {
        $owner = $this->owner(['hotspot']);

        // Quick Links only ever linked to these — removing the dashboard
        // shortcut must not touch the destinations themselves.
        $this->actingAs($owner, 'owner')->get('/users/create')->assertOk();
        $this->actingAs($owner, 'owner')->get('/speed-profiles')->assertOk();
    }

    // --- Default period: last 7 days, not "today" ---

    public function test_dashboard_defaults_to_the_last_7_days_period_without_a_query_param(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        // Inside the default 7-day window (today - 6 .. today).
        $this->booking($owner, $room, '2026-08-22', totalPrice: 77.0);
        // Outside it — must never contribute to any default-period chart.
        $this->booking($owner, $room, '2026-08-10', totalPrice: 999.0);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');
        $html = $response->getContent();

        $response->assertOk();
        // The "7 Days" period button is the one marked active by default.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*period=7d[^"]*"\s+class="[^"]*bg-brand-600[^"]*"/',
            $html,
        );

        $revenueTrend = $this->extractChartSpec($html, 'ls-revenue-trend');
        $this->assertEqualsWithDelta(77.0, array_sum($revenueTrend['datasets'][0]['data']), 0.001);

        $bookingsActivity = $this->extractChartSpec($html, 'ls-bookings-activity');
        $this->assertSame(1, array_sum($bookingsActivity['datasets'][0]['data']));
    }

    public function test_revenue_today_kpi_stays_todays_figure_even_though_charts_default_to_7_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        // Within the new 7-day chart default, but not today — must never
        // leak into the Revenue Today KPI, which stays AnalyticsPeriod::today().
        $this->booking($owner, $room, '2026-08-22', totalPrice: 77.0);

        $response = $this->actingAs($owner, 'owner')->get('/dashboard');
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('EGP 0.00'); // Revenue Today: nothing booked today itself.

        // The chart, meanwhile, correctly reflects the 7-day window's real total.
        $revenueTrend = $this->extractChartSpec($html, 'ls-revenue-trend');
        $this->assertEqualsWithDelta(77.0, array_sum($revenueTrend['datasets'][0]['data']), 0.001);
    }

    private function extractChartSpec(string $html, string $id): array
    {
        preg_match('/<script type="application\/json" id="'.preg_quote($id, '/').'-spec">(.*?)<\/script>/s', $html, $matches);
        $this->assertNotEmpty($matches, "Could not find the {$id} chart spec in the dashboard HTML.");

        return json_decode($matches[1], true);
    }
}
