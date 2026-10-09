<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\Sale;
use App\Models\SharedSession;
use App\Models\Staff;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Super Admin → Business 360° (Phase 1): overview figures, health, location
 * filter, strict per-business isolation, the admin audit log, and the
 * admin bugs fixed alongside.
 */
class AdminBusinessTest extends TestCase
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

    private function business(string $name, array $features = ['workspace', 'booking', 'sales'], ?Carbon $expires = null): Owner
    {
        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'g-'.uniqid(), 'max_members' => 3, 'price_per_month' => 500,
            'is_active' => true, 'sort_order' => 1, 'features' => $features,
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => $name.' Owner', 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name,
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subMonth(), 'subscription_expires_at' => $expires ?? now()->addMonth(),
        ]);
        foreach ($features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function location(Owner $owner, string $name): Workspace
    {
        return Workspace::create(['owner_id' => $owner->id, 'name' => $name, 'is_active' => true]);
    }

    private function room(Workspace $ws, string $name, float $rate = 100, string $type = 'meeting'): Room
    {
        return Room::create(['owner_id' => $ws->owner_id, 'workspace_id' => $ws->id, 'name' => $name, 'type' => $type, 'capacity' => 6, 'price_per_hour' => $rate]);
    }

    private function member(Owner $owner, string $name = 'Member'): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $owner->id, 'name' => $name, 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    private function booking(Room $room, string $date, string $status, float $total, float $paid, string $start = '10:00', string $end = '11:00'): Booking
    {
        return Booking::create([
            'owner_id' => $room->owner_id, 'room_id' => $room->id, 'hotspot_user_id' => $this->member(Owner::find($room->owner_id), 'Cust '.uniqid())->id,
            'party_size' => 1, 'booking_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'price_per_hour' => $total, 'total_hours' => 1, 'total_price' => $total, 'amount_paid' => $paid, 'status' => $status,
        ]);
    }

    /** Business A with two locations and known figures; business B with distinctive data that must never leak. */
    private function seedTwoBusinesses(): array
    {
        $a = $this->business('Alpha');
        $cairo = $this->location($a, 'Cairo');
        $giza = $this->location($a, 'Giza');
        $r1 = $this->room($cairo, 'Alpha Cairo Room');
        $r2 = $this->room($giza, 'Alpha Giza Room');
        $this->booking($r1, '2026-10-15', 'completed', 300, 300);           // today, paid
        $this->booking($r2, '2026-10-15', 'confirmed', 200, 50, '12:00', '14:00'); // today, 150 due
        $this->booking($r1, '2026-10-03', 'completed', 400, 400);            // this month
        $this->booking($r1, '2026-09-20', 'completed', 999, 999);            // last month
        $this->booking($r1, '2026-10-15', 'cancelled', 500, 0, '15:00', '16:00');
        Sale::create(['owner_id' => $a->id, 'status' => 'completed', 'sold_at' => now(), 'subtotal' => 80, 'total' => 80]);
        Expense::create(['owner_id' => $a->id, 'amount' => 120, 'expense_date' => '2026-10-10', 'note' => 'Internet']);

        $b = $this->business('Bravo');
        $bws = $this->location($b, 'Bravo HQ');
        $br = $this->room($bws, 'Bravo Secret Room', 777);
        $this->booking($br, '2026-10-15', 'completed', 7777, 7777);
        Sale::create(['owner_id' => $b->id, 'status' => 'completed', 'sold_at' => now(), 'subtotal' => 5555, 'total' => 5555]);
        $this->member($b, 'Bravo Secret Member');

        return [$a, $cairo, $giza, $b];
    }

    private function overview(Owner $owner, array $query = [])
    {
        return $this->actingAs($this->admin, 'admin')->get('/admin/owners/'.$owner->id.($query ? '?'.http_build_query($query) : ''));
    }

    // ------------------------------------------------------------------ overview + isolation

    public function test_overview_figures_are_accurate_and_only_this_business(): void
    {
        [$a, , , $b] = $this->seedTwoBusinesses();

        $res = $this->overview($a)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Business/Overview'));
        $summary = $res->inertiaProps('summary');

        $this->assertSame(2, $summary['locations']);
        $this->assertSame(2, $summary['rooms']);
        $this->assertSame(2, $summary['bookings_today'], 'Cancelled excluded.');
        $this->assertEquals(300 + 80, $summary['revenue_today'], 'Paid completed bookings + product sales (owner Financials rule).');
        $this->assertEquals(300 + 400 + 80, $summary['revenue_month']);
        $this->assertEquals(120, $summary['expenses_month']);
        $this->assertEquals(150, $summary['outstanding']);
        $this->assertSame(HotspotUser::where('owner_id', $a->id)->count(), $summary['members']);

        // Amounts are formatted client-side now, so the raw figures are checked too.
        $html = $res->getContent();
        $props = json_encode($res->inertiaProps(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['Bravo', 'Bravo Secret Room', 'Bravo Secret Member', '7,777', '5,555', 'Bravo HQ'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "Business B data leaked: {$leak}");
            $this->assertStringNotContainsString($leak, $props, "Business B data leaked: {$leak}");
        }
        foreach (['7777', '5555'] as $leak) {
            $this->assertStringNotContainsString($leak, $props, "Business B amount leaked: {$leak}");
        }
        foreach (['Alpha', 'Cairo', 'Giza'] as $mine) {
            $this->assertStringContainsString($mine, $props);
        }
    }

    public function test_location_filter_narrows_room_figures_and_rejects_foreign_locations(): void
    {
        [$a, $cairo, $giza, $b] = $this->seedTwoBusinesses();

        $s = $this->overview($a, ['workspace' => $giza->id])->assertOk()->inertiaProps('summary');
        $this->assertSame(1, $s['rooms']);
        $this->assertSame(1, $s['bookings_today']);
        $this->assertEquals(0, $s['revenue_today'], 'Giza has no paid completed booking today; product sales are business-wide and not location-bound.');
        $this->assertEquals(150, $s['outstanding']);

        $s = $this->overview($a, ['workspace' => $cairo->id])->inertiaProps('summary');
        $this->assertEquals(300, $s['revenue_today']);
        $this->assertEquals(700, $s['revenue_month']);

        $bLocation = Workspace::where('owner_id', $b->id)->first();
        $this->overview($a, ['workspace' => $bLocation->id])->assertNotFound();
    }

    public function test_active_sessions_count_uses_the_owner_active_sessions_logic(): void
    {
        $a = $this->business('Alpha');
        $ws = $this->location($a, 'Main');
        $shared = $this->room($ws, 'Lounge', 50, 'shared');
        SharedSession::create(['owner_id' => $a->id, 'room_id' => $shared->id, 'hotspot_user_id' => $this->member($a)->id, 'party_size' => 1,
            'session_date' => '2026-10-15', 'start_time' => '11:00', 'opened_at' => now()->subHour(), 'status' => 'open', 'billing_unit' => 'hour', 'billed_price_per_hour' => 50]);
        $this->booking($this->room($ws, 'Meeting'), '2026-10-15', 'confirmed', 100, 0, '11:30', '13:00'); // in progress now

        $this->assertSame(2, $this->overview($a)->inertiaProps('summary')['active_sessions']);
    }

    public function test_health_flags_real_problems_with_links(): void
    {
        $a = $this->business('Alpha', ['workspace', 'booking', 'sales', 'hotspot'], now()->addDays(3));
        $ws = $this->location($a, 'Main');
        $this->room($ws, 'Free room', 0);
        Product::create(['owner_id' => $a->id, 'name' => 'Cola', 'type' => 'product', 'price' => 10, 'is_active' => true, 'track_stock' => true, 'stock_quantity' => 0]);
        Product::create(['owner_id' => $a->id, 'name' => 'Water', 'type' => 'product', 'price' => 5, 'is_active' => true, 'track_stock' => true, 'stock_quantity' => 2, 'low_stock_threshold' => 5]);
        $this->booking(Room::first(), '2026-10-10', 'completed', 100, 40);
        foreach (range(1, 4) as $i) {
            $this->member($a, "M{$i}");
        }

        $keys = collect($this->overview($a)->assertOk()->inertiaProps('health'))->pluck('key')->all();
        foreach (['sub_expiring', 'rooms_unpriced', 'router_missing', 'out_of_stock', 'low_stock', 'unpaid_bookings', 'over_member_limit', 'no_staff'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertSame('danger', collect($this->overview($a)->inertiaProps('health'))->first()['level'], 'Most severe first.');

        // A healthy business shows the all-clear.
        $ok = $this->business('Okay');
        $this->room($this->location($ok, 'Main'), 'Priced');
        Staff::create(['owner_id' => $ok->id, 'name' => 'S', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        // No health items → the page renders the "healthy" all-clear (admin_biz.healthy).
        $this->overview($ok)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Business/Overview')->where('health', []));
    }

    public function test_empty_business_renders_cleanly(): void
    {
        $empty = $this->business('Empty');
        $res = $this->overview($empty)->assertOk();
        // Empty feed → the page renders admin_biz.no_activity.
        $res->assertInertia(fn (Assert $p) => $p->where('activity', [])->where('locations', []));
        $this->assertContains('no_locations', collect($res->inertiaProps('health'))->pluck('key')->all());
        $this->assertEquals(0, $res->inertiaProps('summary')['revenue_month']);
    }

    // ------------------------------------------------------------------ authorization

    public function test_only_super_admins_can_open_business_pages(): void
    {
        [$a, , , $b] = $this->seedTwoBusinesses();

        $this->get('/admin/owners/'.$a->id)->assertRedirect();
        $this->actingAs($b, 'owner')->get('/admin/owners/'.$a->id)->assertRedirect();
        $this->get('/admin/owners/'.$a->id.'/audit')->assertRedirect();
        $this->actingAs($this->admin, 'admin')->get('/admin/owners/999999')->assertNotFound();
    }

    // ------------------------------------------------------------------ audit log

    public function test_sensitive_actions_are_logged_with_reason(): void
    {
        $a = $this->business('Alpha');

        $this->actingAs($this->admin, 'admin')->put("/admin/owners/{$a->id}/toggle-active", ['reason' => 'Chargeback dispute'])->assertRedirect();
        $this->assertFalse($a->fresh()->is_active);
        $log = AdminAuditLog::firstOrFail();
        $this->assertSame(['owner.suspended', $a->id, $this->admin->id, 'Chargeback dispute', 'Root Admin', 'Alpha'],
            [$log->action, $log->owner_id, $log->admin_id, $log->reason, $log->admin_name, $log->owner_name]);

        $this->actingAs($this->admin, 'admin')->put("/admin/owners/{$a->id}/toggle-active")->assertRedirect();
        $this->assertSame('owner.activated', AdminAuditLog::latest('id')->first()->action);

        $plan = Plan::first();
        $this->actingAs($this->admin, 'admin')->post("/admin/owners/{$a->id}/renew", ['plan_id' => $plan->id, 'months' => 2, 'notes' => 'Paid cash'])
            ->assertRedirect("/admin/owners/{$a->id}/subscription");
        $renew = AdminAuditLog::where('action', 'subscription.renewed')->firstOrFail();
        $this->assertSame('Paid cash', $renew->reason);
        $this->assertArrayHasKey('before', $renew->metadata);

        $feature = Feature::where('key', 'sales')->firstOrFail();
        $this->actingAs($this->admin, 'admin')->post("/admin/owners/{$a->id}/features/{$feature->id}/toggle")->assertRedirect();
        $this->assertSame(1, AdminAuditLog::where('action', 'feature.toggled_for_owner')->where('owner_id', $a->id)->count());

        // Shown on the business's Admin actions tab — and only there.
        $other = $this->business('Bravo');
        $this->actingAs($this->admin, 'admin')->get("/admin/owners/{$a->id}/audit")->assertOk()->assertSee('Chargeback dispute');
        $this->actingAs($this->admin, 'admin')->get("/admin/owners/{$other->id}/audit")->assertOk()->assertDontSee('Chargeback dispute');
        $this->overview($a)->assertSee('Root Admin');
    }

    // ------------------------------------------------------------------ fixed admin bugs

    public function test_dashboard_expiring_soon_and_revenue_are_correct(): void
    {
        $soon = $this->business('Soon', ['booking'], now()->addDays(3));
        $this->business('Later', ['booking'], now()->addDays(30));
        $room = $this->room($this->location($soon, 'Main'), 'R');
        $this->booking($room, '2026-10-05', 'completed', 500, 200); // revenue = what was paid
        Sale::create(['owner_id' => $soon->id, 'status' => 'completed', 'sold_at' => now(), 'subtotal' => 30, 'total' => 30]);

        // Rebuilt dashboard: the expiring list (≤ 14 days) has only "Soon"; this month's earnings = what was paid + sales.
        $res = $this->actingAs($this->admin, 'admin')->get('/admin/dashboard?preset=this_month')->assertOk();
        $this->assertSame(['Soon'], collect($res->inertiaProps('expiring'))->pluck('business_name')->all());
        $this->assertEquals(230, $res->inertiaProps('kpis')['earnings']['value']);
    }

    public function test_financial_monthly_breakdown_includes_january_to_september(): void
    {
        $a = $this->business('Alpha');
        foreach (['2026-01-10', '2026-05-10', '2026-11-10'] as $i => $date) {
            Subscription::create(['owner_id' => $a->id, 'admin_id' => $this->admin->id, 'plan_id' => $a->plan_id, 'months' => 1,
                'amount_paid' => 100 * ($i + 1), 'starts_at' => $date, 'expires_at' => $date, 'created_at' => $date]);
        }
        Subscription::query()->each(fn ($s) => $s->forceFill(['created_at' => $s->starts_at])->save());

        // Rebuilt financials: platform revenue for any month (January included) comes from the recorded payments.
        $revenue = fn (string $from, string $to) => $this->actingAs($this->admin, 'admin')
            ->get("/admin/financial?preset=custom&from={$from}&to={$to}")->assertOk()->inertiaProps('cards.platform_revenue.value');
        $this->assertEquals(100, $revenue('2026-01-01', '2026-01-31'));
        $this->assertEquals(200, $revenue('2026-05-01', '2026-05-31'));
        $this->assertEquals(300, $revenue('2026-11-01', '2026-11-30'));
        $this->assertEquals(600, $revenue('2026-01-01', '2026-12-31'));
    }

    public function test_admin_bookings_survive_a_deleted_member_and_errors_are_shown(): void
    {
        $a = $this->business('Alpha');
        $b = $this->booking($this->room($this->location($a, 'Main'), 'R'), '2026-10-15', 'confirmed', 100, 0);
        $b->hotspotUser->delete();

        // A missing member arrives as null and the table shows __('app.admin_biz.deleted_member') for it.
        $this->actingAs($this->admin, 'admin')->get('/admin/bookings')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Bookings/Index')->where('bookings.data.0.id', $b->id)->where('bookings.data.0.customer', null));
        $this->actingAs($this->admin, 'admin')->get('/admin/bookings/'.$b->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Bookings/Show')->where('booking.customer', null));

        $this->actingAs($this->admin, 'admin')->from("/admin/owners/{$a->id}/subscription")
            ->post("/admin/owners/{$a->id}/renew", ['plan_id' => $a->plan_id])
            ->assertSessionHas('error');
        $this->actingAs($this->admin, 'admin')->withSession(['error' => 'Visible error'])->get('/admin/dashboard')->assertSee('Visible error');
    }

    public function test_workspace_page_links_to_its_business_with_the_location_selected(): void
    {
        $a = $this->business('Alpha');
        $ws = $this->location($a, 'Main');
        // Location pages moved to /admin/locations; the old /admin/workspaces/{id} URL redirects there.
        $this->actingAs($this->admin, 'admin')->get('/admin/workspaces/'.$ws->id)->assertRedirect('/admin/locations/'.$ws->id);
        // The page links to /admin/owners/{owner_id}?workspace={id}.
        $this->actingAs($this->admin, 'admin')->get('/admin/locations/'.$ws->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Locations/Show')->where('location.owner_id', $a->id)->where('location.id', $ws->id));
    }
}
