<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Workspace;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * React + Inertia migration: page props are JSON embedded in every response,
 * so this guards that no migrated page ever ships secrets (router password,
 * member/owner password hashes, remember tokens) or another tenant's data,
 * and that guests / unauthorised staff / owners still can't get in.
 */
class InertiaSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTER_SECRET = 'RouterSecret-4821';

    private const MEMBER_SECRET = 'MemberPw-9137';

    private Admin $admin;

    private Owner $alpha;

    private Owner $bravo;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));

        $this->admin = Admin::create(['name' => 'Root', 'email' => 'root@t.local', 'password' => bcrypt('secret123')]);
        $this->alpha = $this->business('Alpha Space');
        $this->bravo = $this->business('Bravo Hidden Co');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(string $name): Owner
    {
        $plan = Plan::create([
            'name' => 'Plan '.substr(md5($name), 0, 6), 'slug' => 'pro-'.uniqid(), 'max_members' => 50, 'price_per_month' => 500,
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace', 'booking', 'sales', 'hotspot'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => $name.' Owner', 'email' => strtolower(str_replace(' ', '', $name)).'@t.local', 'password' => 'secret123',
            'business_name' => $name, 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now()->subMonth(), 'subscription_expires_at' => now()->addMonths(2),
            'mikrotik_host' => '10.0.0.1', 'mikrotik_port' => 8728, 'mikrotik_username' => 'admin', 'mikrotik_password' => self::ROUTER_SECRET,
        ]);
        foreach (['workspace', 'booking', 'sales', 'hotspot'] as $key) {
            $owner->enableFeature($key);
        }
        $owner->forceFill(['remember_token' => 'RememberTok-'.$owner->id])->save();

        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => $name.' HQ', 'is_active' => true]);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name.' Room', 'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 100, 'is_available' => true]);
        $member = HotspotUser::create(['owner_id' => $owner->id, 'name' => $name.' Member', 'phone' => '010'.rand(10000000, 99999999), 'password' => self::MEMBER_SECRET]);
        $booking = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id, 'party_size' => 1,
            'booking_date' => '2026-10-15', 'start_time' => '10:00', 'end_time' => '11:00', 'price_per_hour' => 100,
            'total_hours' => 1, 'total_price' => 100, 'amount_paid' => 100, 'status' => 'completed', 'payment_status' => 'paid',
        ]);
        $product = Product::create(['owner_id' => $owner->id, 'name' => $name.' Coffee', 'type' => 'product', 'price' => 30, 'track_stock' => true, 'stock_quantity' => 5, 'is_active' => true]);
        $this->ids[$owner->id] = compact('ws', 'room', 'member', 'booking', 'product');

        return $owner;
    }

    /** Every owner page now served by Inertia (GET, no route params beyond the owner's own records). */
    private function ownerPages(): array
    {
        $i = $this->ids[$this->alpha->id];

        return [
            '/dashboard', '/bookings', '/bookings/calendar', '/bookings/availability', '/bookings/'.$i['booking']->id,
            '/users', '/users/create', '/users/'.$i['member']->id, '/users/'.$i['member']->id.'/edit',
            '/workspaces', '/workspaces/create', '/workspaces/'.$i['ws']->id.'/edit',
            '/packages', '/packages/create', '/products', '/products/create', '/products/'.$i['product']->id, '/products/'.$i['product']->id.'/edit',
            '/financials', '/financials/transactions', '/financials/transactions/'.$i['booking']->id,
            '/expenses', '/coupons', '/coupons/create', '/notifications', '/settings', '/profile',
            '/staff', '/staff/create', '/subscription/plans', '/speed-profiles', '/speed-profiles/create',
        ];
    }

    private function adminPages(): array
    {
        $a = $this->alpha->id;
        $i = $this->ids[$a];

        return [
            '/admin/dashboard', '/admin/workspaces', '/admin/owners/create', '/admin/owners/'.$a,
            '/admin/owners/'.$a.'/subscription', '/admin/owners/'.$a.'/users', '/admin/owners/'.$a.'/products',
            '/admin/owners/'.$a.'/products/'.$i['product']->id, '/admin/owners/'.$a.'/rooms', '/admin/owners/'.$a.'/bookings',
            '/admin/owners/'.$a.'/financials', '/admin/owners/'.$a.'/activity', '/admin/owners/'.$a.'/audit',
            '/admin/locations', '/admin/locations/'.$i['ws']->id, '/admin/rooms', '/admin/rooms/'.$i['room']->id,
            '/admin/bookings', '/admin/bookings/'.$i['booking']->id, '/admin/financial', '/admin/plans',
            '/admin/plans/'.$this->alpha->plan_id, '/admin/plans/create', '/admin/subscription-requests',
            '/admin/notifications', '/admin/features',
        ];
    }

    private function assertNoSecrets(string $html, string $url): void
    {
        foreach ([self::ROUTER_SECRET, self::MEMBER_SECRET, 'RememberTok-', '$2y$'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "{$url} leaks a secret ({$secret})");
        }
    }

    public function test_owner_pages_render_with_inertia_and_never_leak_secrets_or_other_tenants(): void
    {
        $problems = [];
        foreach ($this->ownerPages() as $url) {
            $res = $this->actingAs($this->alpha, 'owner')->get($url);
            $html = $res->getContent();
            if ($res->getStatusCode() !== 200) {
                $problems[] = "{$url}: status {$res->getStatusCode()}";

                continue;
            }
            if (! str_contains($html, 'data-page=')) {
                $problems[] = "{$url}: not an Inertia page";
            }
            foreach ([self::ROUTER_SECRET, self::MEMBER_SECRET, 'RememberTok-', '$2y$', 'Bravo Hidden Co'] as $needle) {
                if (str_contains($html, $needle)) {
                    $problems[] = "{$url}: contains '{$needle}'";
                }
            }
        }
        $this->assertSame([], $problems);
    }

    public function test_client_side_visits_return_json_page_objects_without_secrets(): void
    {
        $version = app(HandleInertiaRequests::class)->version(request());
        foreach (['/dashboard', '/users/'.$this->ids[$this->alpha->id]['member']->id, '/settings', '/profile'] as $url) {
            $res = $this->actingAs($this->alpha, 'owner')->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version]);
            $res->assertOk()->assertHeader('X-Inertia', 'true');
            $this->assertNoSecrets($res->getContent(), $url);
        }
    }

    public function test_settings_page_never_receives_the_router_password(): void
    {
        $props = $this->actingAs($this->alpha, 'owner')->get('/settings')->assertOk()->inertiaProps();
        $this->assertStringNotContainsString(self::ROUTER_SECRET, json_encode($props));
        $this->assertTrue((bool) data_get($props, 'router.configured'));
    }

    public function test_admin_pages_render_with_inertia_and_never_leak_secrets(): void
    {
        foreach ($this->adminPages() as $url) {
            $res = $this->actingAs($this->admin, 'admin')->get($url);
            $res->assertOk();
            $this->assertStringContainsString('data-page=', $res->getContent(), "{$url} is not an Inertia page");
            $this->assertNoSecrets($res->getContent(), $url);
        }
    }

    public function test_foreign_records_are_not_found_for_an_owner(): void
    {
        $b = $this->ids[$this->bravo->id];
        foreach (['/bookings/'.$b['booking']->id, '/users/'.$b['member']->id, '/products/'.$b['product']->id,
            '/workspaces/'.$b['ws']->id.'/edit', '/financials/transactions/'.$b['booking']->id] as $url) {
            $status = $this->actingAs($this->alpha, 'owner')->get($url)->getStatusCode();
            $this->assertContains($status, [403, 404], "{$url} must not open another tenant's record (got {$status})");
        }
    }

    public function test_guests_and_owners_are_kept_out(): void
    {
        foreach (array_merge($this->ownerPages(), $this->adminPages()) as $url) {
            $this->get($url)->assertRedirect();
        }
        foreach ($this->adminPages() as $url) {
            $this->actingAs($this->alpha, 'owner')->get($url)->assertRedirect();
        }
    }

    public function test_staff_without_permission_is_refused_on_the_server(): void
    {
        $staff = Staff::create([
            'owner_id' => $this->alpha->id, 'role_id' => null,
            'name' => 'No Grants', 'email' => 'nogrants@t.local', 'password' => 'secret123', 'is_active' => true,
        ]);
        foreach (['/financials', '/expenses', '/coupons', '/products', '/users', '/settings'] as $url) {
            $this->actingAs($staff, 'staff')->get($url)->assertRedirect()->assertSessionHas('permission_denied');
        }
        // Owner-only pages.
        $this->actingAs($staff, 'staff')->get('/staff')->assertRedirect();

        // Granted staff see the page, still without secrets.
        $receptionist = Staff::create([
            'owner_id' => $this->alpha->id, 'role_id' => Role::whereNull('owner_id')->where('key', 'receptionist')->value('id'),
            'name' => 'Desk', 'email' => 'desk@t.local', 'password' => 'secret123', 'is_active' => true,
        ]);
        $receptionist->syncPermissionsFromRole();
        $res = $this->actingAs($receptionist, 'staff')->get('/bookings')->assertOk();
        $this->assertNoSecrets($res->getContent(), '/bookings (staff)');
        $this->assertSame('staff', data_get($res->inertiaProps(), 'auth.type'));
    }

    public function test_a_client_side_visit_to_a_page_still_on_blade_becomes_a_full_page_load(): void
    {
        $res = $this->actingAs($this->alpha, 'owner')->get('/active-sessions', ['X-Inertia' => 'true']);
        $res->assertStatus(409);
        $this->assertStringEndsWith('/active-sessions', (string) $res->headers->get('X-Inertia-Location'));
    }
}
