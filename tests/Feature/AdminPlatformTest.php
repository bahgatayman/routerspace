<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\Sale;
use App\Models\SharedSession;
use App\Models\StaffActivityLog;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\Workspace;
use App\Services\Admin\PlatformAnalyticsService;
use App\Services\AnalyticsPeriod;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Super Admin → platform management: workspace directory, business tabs,
 * platform rooms / bookings / financials, dashboard KPIs + insights, plan
 * delete / migrate, and authorization. Money rules are asserted against
 * hand-computed figures so the admin and owner reports can't drift.
 */
class AdminPlatformTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $this->admin = Admin::create(['name' => 'Root Admin', 'email' => 'root@t.local', 'password' => bcrypt('secret123')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function plan(string $name = 'Growth', float $price = 500): Plan
    {
        return Plan::create([
            'name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'max_members' => 50, 'price_per_month' => $price,
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace', 'booking', 'sales'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
    }

    private function business(string $name, ?Plan $plan = null, ?Carbon $expires = null, bool $active = true): Owner
    {
        $owner = Owner::create([
            'name' => $name.' Owner', 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name,
            'plan_id' => ($plan ?? $this->plan())->id, 'is_active' => $active,
            'subscription_starts_at' => now()->subMonth(), 'subscription_expires_at' => $expires ?? now()->addMonths(2),
        ]);
        foreach (['workspace', 'booking', 'sales'] as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $name, ?Workspace $ws = null, string $type = 'meeting'): Room
    {
        $ws ??= Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => $owner->business_name.' HQ'], ['is_active' => true]);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name, 'type' => $type, 'capacity' => 6, 'price_per_hour' => 100, 'is_available' => true]);
    }

    private function member(Owner $owner, string $name = 'Member'): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $owner->id, 'name' => $name, 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    private function booking(Room $room, string $date, string $status, float $total, float $paid, array $extra = []): Booking
    {
        return Booking::create($extra + [
            'owner_id' => $room->owner_id, 'room_id' => $room->id, 'hotspot_user_id' => $this->member(Owner::find($room->owner_id), 'Cust '.uniqid())->id,
            'party_size' => 1, 'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
            'price_per_hour' => $total, 'total_hours' => 1, 'total_price' => $total, 'amount_paid' => $paid, 'status' => $status,
            'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);
    }

    private function payment(Owner $owner, float $amount, string $at): Subscription
    {
        $s = Subscription::create(['owner_id' => $owner->id, 'admin_id' => $this->admin->id, 'plan_id' => $owner->plan_id, 'months' => 1,
            'amount_paid' => $amount, 'starts_at' => $at, 'expires_at' => Carbon::parse($at)->addMonth()]);
        $s->forceFill(['created_at' => Carbon::parse($at)])->save();

        return $s;
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'admin');
    }

    /** Alpha + Bravo with known money in October 2026 (period "this_month" = Oct 1–31). */
    private function seedPlatform(): array
    {
        $a = $this->business('Alpha');
        $ar = $this->room($a, 'Alpha Room');
        $this->booking($ar, '2026-10-05', 'completed', 300, 300);                        // earned 300
        $this->booking($ar, '2026-10-06', 'completed', 200, 200, ['discount_total' => 50]); // earned 200, GBV 150
        $this->booking($ar, '2026-10-07', 'confirmed', 400, 100);                         // GBV 400, outstanding 300
        $this->booking($ar, '2026-10-08', 'cancelled', 900, 0);                           // excluded from GBV
        $this->booking($ar, '2026-10-09', 'no_show', 700, 0);                             // excluded from GBV
        $this->booking($ar, '2026-10-10', 'completed', 120, 120, ['payment_method' => 'package', 'payment_status' => 'paid']); // package
        Sale::create(['owner_id' => $a->id, 'status' => 'completed', 'sold_at' => '2026-10-12 10:00:00', 'subtotal' => 80, 'total' => 80]);
        Sale::create(['owner_id' => $a->id, 'status' => 'open', 'sold_at' => '2026-10-12 11:00:00', 'subtotal' => 999, 'total' => 999]); // not earned
        $this->payment($a, 500, '2026-10-02 09:00:00');

        $b = $this->business('Bravo');
        $br = $this->room($b, 'Bravo Secret Room');
        $this->booking($br, '2026-10-11', 'completed', 7777, 7777);
        $this->payment($b, 1000, '2026-10-03 09:00:00');
        Product::create(['owner_id' => $b->id, 'name' => 'Bravo Secret Coffee', 'type' => 'product', 'price' => 30, 'track_stock' => true, 'stock_quantity' => 2, 'low_stock_threshold' => 5, 'is_active' => true]);

        return [$a, $b, $ar, $br];
    }

    // ------------------------------------------------------------------ money rules

    public function test_financial_rules_match_hand_computed_figures(): void
    {
        [$a] = $this->seedPlatform();
        $p = AnalyticsPeriod::fromPreset('this_month');
        $svc = app(PlatformAnalyticsService::class);

        $this->assertEquals(1500, $svc->platformRevenue($p));                 // 500 + 1000 subscriptions
        $this->assertEquals(300 + 200 + 120 + 7777 + 80, $svc->earnings($p)); // completed paid + completed sales
        $this->assertEquals(300 + 150 + 400 + 120 + 7777, $svc->gbv($p));     // no cancelled / no-show, net of coupon
        $this->assertEquals(300, $svc->outstanding());                         // only the confirmed booking
        $this->assertEquals(50, $svc->discounts($p));
        $this->assertEquals(120, $svc->packageValue($p));

        // Scoped to Alpha only.
        $f = ['owner_id' => $a->id];
        $this->assertEquals(500, $svc->platformRevenue($p, $f));
        $this->assertEquals(700, $svc->earnings($p, $f));

        $counts = $svc->bookingCounts($p);
        $this->assertSame(7, $counts['total']);
        $this->assertSame(4, $counts['completed']);
        $this->assertEquals(round(2 / 7 * 100, 1), $counts['cancel_rate']);
    }

    public function test_shared_session_money_is_not_counted_twice(): void
    {
        $a = $this->business('Alpha');
        $room = $this->room($a, 'Shared', null, 'shared');
        $booking = $this->booking($room, '2026-10-14', 'completed', 90, 90);
        SharedSession::forceCreate([
            'owner_id' => $a->id, 'room_id' => $room->id, 'hotspot_user_id' => $booking->hotspot_user_id, 'opened_at' => '2026-10-14 09:00:00',
            'closed_at' => '2026-10-14 10:30:00', 'total_minutes' => 90, 'total_price' => 90, 'status' => 'closed', 'booking_id' => $booking->id,
            'session_date' => '2026-10-14', 'start_time' => '09:00',
        ]);

        $this->assertEquals(90, app(PlatformAnalyticsService::class)->earnings(AnalyticsPeriod::fromPreset('this_month')));
        $res = $this->asAdmin()->get('/admin/financial?preset=this_month')->assertOk();
        $this->assertEquals(90, $res->viewData('cards')['earnings']['value']);
        $this->asAdmin()->get('/admin/bookings?type=session')->assertOk()->assertSee('#'.$booking->id);
    }

    public function test_financials_page_filters_and_transactions_table(): void
    {
        [$a, $b] = $this->seedPlatform();

        $res = $this->asAdmin()->get('/admin/financial?preset=this_month')->assertOk();
        $this->assertEquals(1500, $res->viewData('cards')['platform_revenue']['value']);
        $types = collect($res->viewData('transactions')->items())->countBy('type')->all();
        // 2 subscriptions, 5 bookings with money paid (package excluded — no cash), 2 sales (open one listed, flagged not earned).
        $this->assertSame(['booking' => 4, 'sale' => 2, 'subscription' => 2], collect($types)->sortKeys()->all());

        $res = $this->asAdmin()->get("/admin/financial?preset=this_month&owner={$a->id}")->assertOk();
        $this->assertEquals(500, $res->viewData('cards')['platform_revenue']['value']);
        $this->assertEquals(700, $res->viewData('cards')['earnings']['value']);
        $res->assertDontSee('Bravo Secret Room');

        $res = $this->asAdmin()->get('/admin/financial?preset=this_month&type=subscription')->assertOk();
        $this->assertSame(['subscription'], collect($res->viewData('transactions')->items())->pluck('type')->unique()->values()->all());

        $res = $this->asAdmin()->get('/admin/financial?preset=this_month&payment=partial')->assertOk();
        $this->assertSame([100.0], collect($res->viewData('transactions')->items())->pluck('amount')->map(fn ($v) => (float) $v)->all());

        // Untracked metrics are labelled, never estimated.
        $res->assertSee(__('app.admin_platform.not_tracked.refunds.name'))->assertSee(__('app.admin_platform.not_tracked_badge'));
    }

    // ------------------------------------------------------------------ directory

    public function test_workspace_directory_search_filter_sort_and_counts(): void
    {
        [$a, $b] = $this->seedPlatform();
        $this->business('Charlie Expiring', null, now()->addDays(3));
        $this->business('Delta Expired', null, now()->subDays(2));
        $this->business('Echo Suspended', null, now()->addMonth(), false);

        $res = $this->asAdmin()->get('/admin/workspaces?preset=this_month&sort=earnings&dir=desc')->assertOk();
        $owners = $res->viewData('owners');
        $this->assertSame('Bravo', $owners->first()->business_name);
        $this->assertEquals(7777, (float) $owners->first()->booking_earnings);
        $alpha = $owners->firstWhere('id', $a->id);
        $this->assertEquals(620, (float) $alpha->booking_earnings);
        $this->assertEquals(80, (float) $alpha->sales_earnings);
        $this->assertSame(4, $alpha->period_bookings); // cancelled + no-show excluded
        $this->assertSame(1, $alpha->rooms_count);

        $counts = $res->viewData('counts')->all();
        $this->assertSame(['active' => 2, 'expiring' => 1, 'expired' => 1, 'suspended' => 1, 'never' => 0], $counts);

        $this->asAdmin()->get('/admin/workspaces?status=expiring')->assertOk()->assertSee('Charlie Expiring')->assertDontSee('Delta Expired');
        $this->asAdmin()->get('/admin/workspaces?q=alph')->assertOk()->assertSee('Alpha')->assertDontSee('Bravo');
        $this->asAdmin()->get('/admin/workspaces?q='.$b->id)->assertOk()->assertSee('Bravo');
        $this->asAdmin()->get('/admin/workspaces?sort=DROP TABLE&dir=sideways&status=bogus&preset=nope')->assertOk();
        $this->asAdmin()->get('/admin/owners')->assertRedirect('/admin/workspaces');
    }

    // ------------------------------------------------------------------ business tabs + isolation

    public function test_business_tabs_show_only_that_business(): void
    {
        [$a, $b, $ar] = $this->seedPlatform();
        $coffee = Product::where('owner_id', $b->id)->first();
        $mine = Product::create(['owner_id' => $a->id, 'name' => 'Alpha Tea', 'type' => 'product', 'price' => 20, 'track_stock' => true, 'stock_quantity' => 0, 'is_active' => true]);
        StaffActivityLog::create(['owner_id' => $b->id, 'actor_type' => 'owner', 'actor_name' => 'Bravo Boss', 'actor_email' => 'b@t.local', 'action' => 'x', 'subject_type' => Owner::class, 'subject_id' => $b->id, 'description' => 'Bravo secret action']);
        StaffActivityLog::create(['owner_id' => $a->id, 'actor_type' => 'owner', 'actor_name' => 'Alpha Boss', 'actor_email' => 'a@t.local', 'action' => 'x', 'subject_type' => Owner::class, 'subject_id' => $a->id, 'description' => 'Alpha visible action']);

        foreach (['products', 'rooms', 'bookings?preset=this_month', 'financials?preset=this_month', 'activity', 'audit', ''] as $tab) {
            $this->asAdmin()->get("/admin/owners/{$a->id}/{$tab}")->assertOk()
                ->assertDontSee('Bravo Secret')->assertDontSee('7,777')->assertDontSee('Bravo secret action');
        }
        $this->asAdmin()->get("/admin/owners/{$a->id}/products")->assertSee('Alpha Tea');
        $this->asAdmin()->get("/admin/owners/{$a->id}/rooms")->assertSee('Alpha Room');
        $this->asAdmin()->get("/admin/owners/{$a->id}/activity")->assertSee('Alpha visible action');

        $fin = $this->asAdmin()->get("/admin/owners/{$a->id}/financials?preset=this_month")->viewData('cards');
        $this->assertEquals(700, $fin['earnings']['value']);
        $this->assertEquals(500, $fin['platform_revenue']['value']);

        // Product detail is owner-scoped: a foreign product id is a 404, not a leak.
        $this->asAdmin()->get("/admin/owners/{$a->id}/products/{$mine->id}")->assertOk()->assertSee('Alpha Tea');
        $this->asAdmin()->get("/admin/owners/{$a->id}/products/{$coffee->id}")->assertNotFound();
        $bws = Workspace::where('owner_id', $b->id)->first();
        $this->asAdmin()->get("/admin/owners/{$a->id}/rooms?workspace={$bws->id}")->assertNotFound();
        $this->asAdmin()->get("/admin/owners/{$a->id}/bookings?workspace={$bws->id}")->assertNotFound();
    }

    // ------------------------------------------------------------------ rooms

    public function test_platform_rooms_list_filters_and_detail(): void
    {
        [$a, $b, $ar, $br] = $this->seedPlatform();
        $idle = $this->room($a, 'Alpha Idle Room');

        $res = $this->asAdmin()->get('/admin/rooms?preset=this_month')->assertOk()->assertSee('Alpha Room')->assertSee('Bravo Secret Room');
        $this->assertSame(3, $res->viewData('rooms')->total());

        $this->asAdmin()->get("/admin/rooms?owner={$a->id}")->assertOk()->assertSee('Alpha Room')->assertDontSee('Bravo Secret Room');
        $this->asAdmin()->get('/admin/rooms?idle=1&preset=this_month')->assertOk()->assertSee('Alpha Idle Room')->assertDontSee('Bravo Secret Room');
        // A location of another business is ignored when filtering by a business.
        $bws = Workspace::where('owner_id', $b->id)->first();
        $this->assertNull($this->asAdmin()->get("/admin/rooms?owner={$a->id}&location={$bws->id}")->viewData('filters')['location']);

        $show = $this->asAdmin()->get("/admin/rooms/{$ar->id}?preset=this_month")->assertOk()->assertSee('Alpha')->assertDontSee('Bravo Secret Room');
        $stats = $show->viewData('stats');
        $this->assertSame(6, $stats['bookings']);
        $this->assertEquals(620, $stats['earnings']);
        $this->assertEquals(300, $stats['outstanding']);
        $this->asAdmin()->get('/admin/rooms/999999')->assertNotFound();
    }

    // ------------------------------------------------------------------ bookings

    public function test_platform_bookings_filters_stats_and_detail(): void
    {
        [$a, $b, $ar] = $this->seedPlatform();

        $res = $this->asAdmin()->get('/admin/bookings')->assertOk();
        $this->assertSame(7, $res->viewData('stats')['total']);
        $this->assertEquals(300, $res->viewData('stats')['outstanding']);

        $res = $this->asAdmin()->get("/admin/bookings?owner={$a->id}&status=cancelled")->assertOk();
        $this->assertSame(['cancelled'], collect($res->viewData('bookings')->items())->pluck('status')->unique()->values()->all());
        $this->assertSame(6, $res->viewData('stats')['total']); // stats ignore the status chip, respect the owner

        $due = $this->asAdmin()->get('/admin/bookings?payment=due')->viewData('bookings');
        $this->assertSame(['confirmed'], collect($due->items())->pluck('status')->all());
        $pkg = $this->asAdmin()->get('/admin/bookings?type=package')->viewData('bookings');
        $this->assertSame(1, $pkg->total());
        $range = $this->asAdmin()->get('/admin/bookings?preset=custom&from=2026-10-05&to=2026-10-06')->viewData('bookings');
        $this->assertSame(2, $range->total());

        $one = Booking::where('owner_id', $a->id)->where('status', 'confirmed')->first();
        $this->asAdmin()->get('/admin/bookings?q='.$one->id)->assertOk()->assertSee('/admin/bookings/'.$one->id, false);
        $this->asAdmin()->get('/admin/bookings/'.$one->id)->assertOk()->assertSee('Alpha Room')->assertSee('300.00');
        $this->asAdmin()->get('/admin/bookings/999999')->assertNotFound();
    }

    // ------------------------------------------------------------------ dashboard + insights

    public function test_dashboard_kpis_compare_with_the_previous_period(): void
    {
        $a = $this->business('Alpha');
        $room = $this->room($a, 'R');
        $this->booking($room, '2026-10-10', 'completed', 300, 300); // last 30 days
        $this->booking($room, '2026-09-01', 'completed', 100, 100); // previous 30 days

        $kpis = $this->asAdmin()->get('/admin/dashboard')->assertOk()->viewData('kpis');
        $this->assertEquals(300, $kpis['earnings']['value']);
        $this->assertEquals(100, $kpis['earnings']['previous']);
        $this->assertEquals(200.0, $kpis['earnings']['change']);
        $this->assertNull($kpis['platform_revenue']['change']); // 0 → 0: no fake percentage
        $this->assertEquals(500, $kpis['mrr']['value']);
    }

    public function test_insights_fire_only_when_the_data_supports_them(): void
    {
        $falling = $this->business('Falling');
        $fr = $this->room($falling, 'F');
        $this->booking($fr, '2026-09-01', 'completed', 1000, 1000); // previous period
        $this->booking($fr, '2026-10-10', 'completed', 100, 100);   // now: −90%

        $flaky = $this->business('Flaky');
        $kr = $this->room($flaky, 'K');
        foreach (range(1, 10) as $i) {
            $this->booking($kr, '2026-10-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i <= 4 ? 'cancelled' : 'completed', 50, $i <= 4 ? 0 : 50);
        }

        $this->business('Soon', null, now()->addDays(4));
        $this->business('Gone', null, now()->subDays(5));
        $this->business('Empty Shell');

        $keys = collect($this->asAdmin()->get('/admin/dashboard')->assertOk()->viewData('insights')['items'])->pluck('key');
        foreach (['declining', 'cancellations', 'expiring', 'expired', 'no_rooms', 'top_earnings'] as $k) {
            $this->assertContains($k, $keys, "missing insight {$k}");
        }

        // A tiny business with 2 bookings is never flagged for cancellations or decline.
        $this->refreshApplication();
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $this->artisan('migrate:fresh');
        $this->seed(FeatureSeeder::class);
        $admin = Admin::create(['name' => 'A', 'email' => 'a@t.local', 'password' => bcrypt('secret123')]);
        $small = $this->business('Small');
        $sr = $this->room($small, 'S');
        $this->booking($sr, '2026-10-10', 'cancelled', 50, 0);
        $this->booking($sr, '2026-09-01', 'completed', 100, 100);
        $keys = collect($this->actingAs($admin, 'admin')->get('/admin/dashboard')->viewData('insights')['items'])->pluck('key');
        $this->assertNotContains('cancellations', $keys);
        $this->assertNotContains('declining', $keys);
    }

    public function test_empty_platform_renders_every_admin_page(): void
    {
        foreach (['/admin/dashboard', '/admin/workspaces', '/admin/locations', '/admin/rooms', '/admin/bookings', '/admin/financial', '/admin/plans',
            '/admin/dashboard?preset=custom&from=2025-01-01&to=2026-10-15', '/admin/financial?preset=today'] as $url) {
            $this->asAdmin()->get($url)->assertOk();
        }
        $this->asAdmin()->get('/admin/dashboard')->assertSee(__('app.admin_platform.chart.empty'));
    }

    public function test_pages_render_in_arabic(): void
    {
        [$a, $b, $ar] = $this->seedPlatform();
        $plan = Plan::first();
        foreach (['/admin/dashboard', '/admin/workspaces', '/admin/rooms', '/admin/rooms/'.$ar->id, '/admin/bookings', '/admin/financial', '/admin/plans', '/admin/plans/'.$plan->id,
            "/admin/owners/{$a->id}/financials", "/admin/owners/{$a->id}/products"] as $url) {
            $this->asAdmin()->withSession(['locale' => 'ar'])->get($url)->assertOk()->assertSee('dir="rtl"', false);
        }
    }

    // ------------------------------------------------------------------ plans

    public function test_unused_plan_can_be_deleted_used_plan_cannot(): void
    {
        $unused = $this->plan('Unused');
        $this->asAdmin()->delete("/admin/plans/{$unused->id}", ['reason' => 'cleanup'])->assertRedirect('/admin/plans');
        $this->assertModelMissing($unused);
        $this->assertSame('cleanup', AdminAuditLog::where('action', 'plan.deleted')->value('reason'));

        $used = $this->plan('Used');
        $this->business('On It', $used);
        $this->asAdmin()->from('/admin/plans')->delete("/admin/plans/{$used->id}")->assertSessionHas('error');
        $this->assertModelExists($used);
        $this->asAdmin()->deleteJson("/admin/plans/{$used->id}")->assertStatus(422)->assertJsonPath('usage.owners', 1);

        // A renewal request alone also counts as "used".
        $requested = $this->plan('Requested');
        SubscriptionRequest::create(['owner_id' => Owner::first()->id, 'plan_id' => $requested->id, 'months' => 1, 'amount' => 10, 'status' => 'pending']);
        $this->asAdmin()->delete("/admin/plans/{$requested->id}");
        $this->assertModelExists($requested);
    }

    public function test_migrate_moves_businesses_audits_and_can_deactivate(): void
    {
        $old = $this->plan('Old', 300);
        $new = $this->plan('New', 800);
        $x = $this->business('Xray', $old);
        $y = $this->business('Yankee', $old);
        $z = $this->business('Zulu', $new);
        $this->payment($x, 300, '2026-10-01 10:00:00');

        $this->asAdmin()->post("/admin/plans/{$old->id}/migrate", ['target_plan_id' => $new->id, 'deactivate' => 1, 'reason' => 'Retired'])
            ->assertRedirect("/admin/plans/{$old->id}");

        $this->assertSame([$new->id, $new->id, $new->id], [$x->fresh()->plan_id, $y->fresh()->plan_id, $z->fresh()->plan_id]);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame($old->id, Subscription::first()->plan_id); // history untouched
        $this->assertSame(2, AdminAuditLog::where('action', 'owner.plan_changed')->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'plan.migrated')->where('reason', 'Retired')->count());

        foreach ([$old->id, 999999, null] as $bad) {
            $this->asAdmin()->from("/admin/plans/{$old->id}")->post("/admin/plans/{$old->id}/migrate", ['target_plan_id' => $bad])
                ->assertRedirect("/admin/plans/{$old->id}")->assertSessionHasErrors('target_plan_id');
        }
        $this->asAdmin()->postJson("/admin/plans/{$new->id}/toggle")->assertOk()->assertJsonPath('is_active', false);
        $this->asAdmin()->get("/admin/plans/{$new->id}")->assertOk()->assertSee('Xray')->assertSee('Zulu');
    }

    public function test_plan_cards_show_usage(): void
    {
        $plan = $this->plan('Pro', 500);
        $this->business('P1', $plan);
        $this->business('P2', $plan, now()->subDay());
        $res = $this->asAdmin()->get('/admin/plans')->assertOk();
        $card = $res->viewData('plans')->firstWhere('id', $plan->id);
        $this->assertSame(2, $card->owners_count);
        $this->assertSame(1, $card->active_owners_count);
        $res->assertSee('500.00');
    }

    // ------------------------------------------------------------------ authorization

    public function test_guests_and_owners_cannot_reach_admin_pages(): void
    {
        [$a, $b, $ar] = $this->seedPlatform();
        $plan = Plan::first();
        $urls = ['/admin/dashboard', '/admin/workspaces', '/admin/locations', '/admin/rooms', '/admin/rooms/'.$ar->id, '/admin/bookings',
            '/admin/financial', '/admin/plans', '/admin/plans/'.$plan->id, "/admin/owners/{$a->id}/products", "/admin/owners/{$a->id}/financials"];
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect();
            $this->actingAs($b, 'owner')->get($url)->assertRedirect();
        }
        $this->actingAs($b, 'owner')->delete("/admin/plans/{$plan->id}")->assertRedirect();
        $this->actingAs($b, 'owner')->post("/admin/plans/{$plan->id}/migrate", ['target_plan_id' => $plan->id])->assertRedirect();
        $this->assertModelExists($plan);
    }
}
