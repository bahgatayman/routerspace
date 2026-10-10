<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Room;
use App\Models\Staff;
use App\Models\StaffActivityLog;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Staff Accounts & Role-Based Permissions — the security-critical matrix from
 * the approved architecture proposal: tenant isolation, guard-level
 * separation, permission enforcement, disabled-account propagation, and the
 * activity log being the sole source of truth for staff accountability.
 */
class StaffAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    /**
     * actingAs() only sets the given guard's user — it never clears a
     * different guard set earlier in the same test — so switching between
     * an owner and a staff actor within one test leaks the previous guard's
     * session into the next request. Real browsers can never have two
     * guards authenticated at once (login only ever picks one), so this is
     * purely a test-isolation need, not a case the app itself has to handle.
     */
    private function asGuard($user, string $guard): static
    {
        auth('owner')->logout();
        auth('staff')->logout();
        auth('admin')->logout();

        return $this->actingAs($user, $guard);
    }

    private function owner(array $features = ['workspace', 'booking']): Owner
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

    private function room(Owner $owner): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room',
            'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 40,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '01'.rand(100000000, 999999999),
            'password' => 'x', 'status' => 'active',
        ]);
    }

    private function booking(Owner $owner, Room $room, HotspotUser $member): Booking
    {
        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'party_size' => 1, 'booking_date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '10:00', 'end_time' => '11:00',
            'price_per_hour' => $room->price_per_hour, 'total_hours' => 1, 'total_price' => 40,
            'status' => 'confirmed',
        ]);
    }

    /** A receptionist-role staff member, active, granted bookings.view/create/edit. */
    private function staff(Owner $owner, string $roleKey = 'receptionist'): Staff
    {
        $role = Role::whereNull('owner_id')->where('key', $roleKey)->firstOrFail();

        $staff = Staff::create([
            'owner_id' => $owner->id, 'role_id' => $role->id,
            'name' => 'Staffer', 'email' => 's'.uniqid().'@t.local',
            'password' => 'secret123', 'is_active' => true,
        ]);
        $staff->syncPermissionsFromRole();

        return $staff;
    }

    public function test_staff_can_log_in_and_reach_the_dashboard(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);

        $response = $this->post('/login', ['email' => $staff->email, 'password' => 'secret123']);

        $response->assertRedirect('/dashboard');
        $this->assertTrue(auth('staff')->check());
        $this->assertEquals($staff->id, auth('staff')->id());

        // Follow the redirect: the landing page itself must never be
        // permission-gated, or a staff member missing that one permission
        // would bounce straight back into an infinite redirect loop.
        $this->actingAs($staff, 'staff')->get('/dashboard')->assertOk();
    }

    public function test_a_staff_member_with_zero_permissions_can_still_reach_the_dashboard(): void
    {
        $owner = $this->owner();
        $staff = Staff::create([
            'owner_id' => $owner->id, 'role_id' => null,
            'name' => 'No Grants', 'email' => 'nogrants'.uniqid().'@t.local',
            'password' => 'secret123', 'is_active' => true,
        ]);

        $response = $this->actingAs($staff, 'staff')->get('/dashboard');

        $response->assertOk();
    }

    public function test_disabled_staff_cannot_log_in(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);
        $staff->update(['is_active' => false]);

        $response = $this->post('/login', ['email' => $staff->email, 'password' => 'secret123']);

        $response->assertSessionHasErrors('email');
        $this->assertFalse(auth('staff')->check());
    }

    public function test_a_live_staff_session_is_force_logged_out_the_moment_the_account_is_disabled(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);

        $this->actingAs($staff, 'staff')->get('/dashboard')->assertOk();

        $staff->update(['is_active' => false]);

        $response = $this->actingAs($staff, 'staff')->get('/dashboard');

        $response->assertRedirect('/login');
        $this->assertFalse(auth('staff')->check());
    }

    public function test_staff_management_routes_are_completely_unreachable_via_the_staff_guard(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner, 'manager'); // even a Manager, broadest bundle

        // /staff/* is registered on auth:owner only — a staff session is not
        // authenticated on the owner guard at all, so it's treated as a guest here.
        $this->actingAs($staff, 'staff')->get('/staff')->assertRedirect('/login');
        $this->actingAs($staff, 'staff')->get('/staff/create')->assertRedirect('/login');
    }

    public function test_staff_cannot_reach_another_owners_booking_by_guessing_the_id(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $roomB = $this->room($ownerB);
        $memberB = $this->member($ownerB);
        $bookingB = $this->booking($ownerB, $roomB, $memberB);

        $staffA = $this->staff($ownerA);

        $response = $this->actingAs($staffA, 'staff')->get("/bookings/{$bookingB->id}");

        $response->assertStatus(404);
    }

    public function test_staff_without_a_permission_is_redirected_not_permitted(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner); // Receptionist: no settings.view/settings.manage

        // Neither the page nor the write endpoint are in the Receptionist
        // bundle, so both are denied server-side — not just hidden from nav.
        // CheckPermission denies via back()->with('permission_denied', ...),
        // rendered as a modal (see layouts/app.blade.php) rather than a hard
        // redirect to a fixed page, so only the flash is asserted here.
        $this->actingAs($staff, 'staff')->get('/settings')->assertSessionHas('permission_denied');

        $postResponse = $this->actingAs($staff, 'staff')->post('/settings/working-hours', ['hours' => []]);
        $postResponse->assertSessionHas('permission_denied');
    }

    public function test_dashboard_hides_revenue_from_staff_without_reports_view_but_shows_it_to_a_manager(): void
    {
        $owner = $this->owner(['workspace', 'booking', 'sales']);
        $receptionist = $this->staff($owner, 'receptionist');
        $manager = $this->staff($owner, 'manager');

        $asReceptionist = $this->asGuard($receptionist, 'staff')->get('/dashboard');
        $asReceptionist->assertOk();
        $asReceptionist->assertDontSee(__('app.dashboard.revenue_today'));
        $asReceptionist->assertDontSee(__('app.dashboard.revenue_trend'));

        $asManager = $this->asGuard($manager, 'staff')->get('/dashboard');
        $asManager->assertOk();
        $asManager->assertSee(__('app.dashboard.revenue_today'));
        $asManager->assertSee(__('app.dashboard.revenue_trend'));

        $asOwner = $this->asGuard($owner, 'owner')->get('/dashboard');
        $asOwner->assertOk();
        $asOwner->assertSee(__('app.dashboard.revenue_today'));
    }

    public function test_dashboard_hides_workspace_overview_from_staff_without_the_permission(): void
    {
        $owner = $this->owner(['workspace', 'booking']);
        $this->room($owner); // gives workspaces/rooms something to count
        $noGrants = Staff::create([
            'owner_id' => $owner->id, 'role_id' => null,
            'name' => 'No Grants', 'email' => 'nogrants'.uniqid().'@t.local',
            'password' => 'secret123', 'is_active' => true,
        ]);
        $manager = $this->staff($owner, 'manager'); // has workspaces.view

        $asNoGrants = $this->asGuard($noGrants, 'staff')->get('/dashboard');
        $asNoGrants->assertOk();
        $asNoGrants->assertDontSee(__('app.dashboard.current_occupancy'));
        $asNoGrants->assertDontSee(__('app.label.available_rooms'));

        $asManager = $this->asGuard($manager, 'staff')->get('/dashboard');
        $asManager->assertOk();
        $asManager->assertSee(__('app.dashboard.current_occupancy'));

        $asOwner = $this->asGuard($owner, 'owner')->get('/dashboard');
        $asOwner->assertOk();
        $asOwner->assertSee(__('app.dashboard.current_occupancy'));
    }

    public function test_receptionist_can_create_a_booking_but_not_cancel_one(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $staff = $this->staff($owner); // Receptionist: bookings.create/edit, no bookings.cancel

        $create = $this->actingAs($staff, 'staff')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '10:00', 'end_time' => '11:00',
        ]);
        $create->assertRedirect();
        $booking = Booking::where('owner_id', $owner->id)->firstOrFail();

        $cancel = $this->actingAs($staff, 'staff')->post("/bookings/{$booking->id}/status", ['status' => 'cancelled']);
        $cancel->assertSessionHas('permission_denied');
        $this->assertEquals('confirmed', $booking->fresh()->status);
    }

    public function test_manager_role_can_cancel_a_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $staff = $this->staff($owner, 'manager');

        $response = $this->actingAs($staff, 'staff')->post("/bookings/{$booking->id}/status", ['status' => 'cancelled']);

        $response->assertRedirect();
        $this->assertEquals('cancelled', $booking->fresh()->status);
    }

    public function test_revoking_a_permission_blocks_the_very_next_request(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $staff = $this->staff($owner);

        $this->actingAs($staff, 'staff')->get('/bookings/create')->assertOk();

        $bookingsCreate = Permission::where('key', 'bookings.create')->firstOrFail();
        $staff->permissions()->detach($bookingsCreate->id);

        $response = $this->actingAs($staff, 'staff')->get('/bookings/create');

        $response->assertSessionHas('permission_denied');
    }

    public function test_a_new_staff_member_defaults_to_the_receptionist_bundle_with_no_financial_access(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);

        $this->assertTrue($staff->hasPermission('bookings.create'));
        $this->assertFalse($staff->hasPermission('sales.view'));
        $this->assertFalse($staff->hasPermission('reports.view'));
        $this->assertFalse($staff->hasPermission('staff.manage'));
    }

    public function test_booking_created_by_staff_is_recorded_in_the_activity_log_with_the_actor_attributed(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $staff = $this->staff($owner);

        $this->actingAs($staff, 'staff')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '10:00', 'end_time' => '11:00',
        ])->assertRedirect();

        $log = StaffActivityLog::where('owner_id', $owner->id)->where('action', 'booking.created')->first();

        $this->assertNotNull($log);
        $this->assertEquals($staff->id, $log->staff_id);
        $this->assertEquals('staff', $log->actor_type);
        $this->assertEquals($staff->name, $log->actor_name);
    }

    public function test_the_activity_log_survives_the_staff_member_being_deleted(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $staff = $this->staff($owner);

        $this->actingAs($staff, 'staff')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '10:00', 'end_time' => '11:00',
        ])->assertRedirect();

        $staffName = $staff->name;
        $staff->update(['is_active' => false]);
        $staff->delete();

        $log = StaffActivityLog::where('owner_id', $owner->id)->where('action', 'booking.created')->firstOrFail();

        $this->assertEquals($staffName, $log->actor_name);
    }

    public function test_owner_can_open_a_staff_members_activity_page_and_counts_are_derived_not_stored(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $staff = $this->staff($owner);

        $this->actingAs($staff, 'staff')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '10:00', 'end_time' => '11:00',
        ])->assertRedirect();

        $this->assertFalse(Schema::hasColumn('staff', 'bookings_created_count'));

        $response = $this->actingAs($owner, 'owner')->get("/staff/{$staff->id}/activity?range=all");

        $response->assertOk();
        $response->assertSee('1');
    }

    public function test_the_staff_edit_screen_is_fully_translated_in_arabic(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner, 'manager'); // broadest bundle exercises every permission/group key

        $response = $this->withSession(['locale' => 'ar'])
            ->actingAs($owner, 'owner')->get("/staff/{$staff->id}/edit");

        $response->assertOk();
        // Asserted against literal strings, not __() — a call to __() would
        // fail the exact same way the page does if the lookup key breaks
        // again, making the assertion pass even while the page is broken.
        $response->assertSee('الحجوزات'); // permission_group.bookings
        $response->assertSee('إلغاء الحجوزات'); // permission.bookings.cancel
        $response->assertSee('مدير'); // role.manager
        $response->assertDontSee('Cancel Bookings', false);
        $response->assertDontSee('Manager', false);
        // Permission.key and StaffActivityLog.action both contain literal
        // dots (domain.action) — Laravel's translator splits every dot in
        // the lookup as a nesting level, so a flat 'bookings.cancel' => ...
        // array key silently never resolves and __() returns the raw
        // lookup string. Guard against that regressing unnoticed.
        $response->assertDontSee('app.permission.', false);
        $response->assertDontSee('app.permission_group.', false);
        $response->assertDontSee('app.role.', false);
    }

    public function test_the_staff_activity_page_event_labels_are_translated_in_arabic(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $staff = $this->staff($owner);

        $this->actingAs($staff, 'staff')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '10:00', 'end_time' => '11:00',
        ])->assertRedirect();

        $response = $this->withSession(['locale' => 'ar'])
            ->actingAs($owner, 'owner')->get("/staff/{$staff->id}/activity?range=all");

        $response->assertOk();
        $response->assertSee('تم إنشاء حجز'); // events.booking.created
        $response->assertDontSee('app.staff.events.', false);
    }

    public function test_owner_can_view_the_staff_management_screens(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);

        $this->actingAs($owner, 'owner')->get('/staff')->assertOk()->assertSee($staff->name);
        $this->actingAs($owner, 'owner')->get('/staff/create')->assertOk();
        $this->actingAs($owner, 'owner')->get("/staff/{$staff->id}/edit")->assertOk()->assertSee($staff->email);
    }

    /**
     * Regression test for a reported bug: the create/edit permissions grid
     * only ever renders the rows present in the `permissions` table, and on
     * an environment where only `php artisan migrate` had ever run (no
     * `db:seed`), that table held just the 2 `financials.*` rows inserted
     * directly by a migration — every other permission only ever existed
     * via PermissionSeeder. See database/migrations/
     * 2026_09_29_000001_seed_full_permission_catalog.php, which now makes
     * the full catalog migration-only too. This test asserts every group
     * and every permission actually renders, not just that the page is 200.
     */
    public function test_all_permission_groups_and_permissions_render_on_create_and_edit(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);

        $permissions = Permission::where('is_active', true)->get();
        $groups = $permissions->pluck('group')->unique();

        $this->assertSame(39, $permissions->count(), 'the permission catalog itself should have 39 rows');
        $this->assertSame(13, $groups->count(), 'the permission catalog itself should have 13 groups');

        foreach ([
            '/staff/create' => $this->actingAs($owner, 'owner')->get('/staff/create'),
            "/staff/{$staff->id}/edit" => $this->actingAs($owner, 'owner')->get("/staff/{$staff->id}/edit"),
        ] as $url => $response) {
            $response->assertOk();

            foreach ($groups as $group) {
                $response->assertSee(__('app.permission_group.'.$group));
            }

            foreach ($permissions as $permission) {
                $response->assertSee('value="'.$permission->id.'"', false);
                $response->assertSee(__('app.permission.'.$permission->key));
            }
        }
    }

    /**
     * Companion to the permissions-render test above: RoleSeeder (and its
     * role->permissions sync) has the identical migrate-only-environment
     * gap — it only ever ran via `db:seed`. An environment that only ran
     * `migrate` had a completely EMPTY `roles` table, so the Staff form's
     * Role dropdown had nothing in it at all. See database/migrations/
     * 2026_09_29_000002_seed_system_roles_and_role_permissions.php.
     */
    public function test_all_system_roles_exist_with_their_full_permission_bundles(): void
    {
        // +2 receptionist/staff and +3 manager from 2026_10_01_000003 (Hour Packages).
        $expectedCounts = ['receptionist' => 12, 'staff' => 14, 'manager' => 33];

        $roles = Role::whereNull('owner_id')->get()->keyBy('key');

        $this->assertEqualsCanonicalizing(array_keys($expectedCounts), $roles->keys()->all());

        foreach ($expectedCounts as $key => $count) {
            $this->assertTrue($roles[$key]->is_system);
            $this->assertSame($count, $roles[$key]->permissions()->count(), "role '{$key}' permission count");
        }

        // Manager is a strict superset of Staff, which is a strict superset
        // of Receptionist — matches RoleSeeder's array_merge() bundling.
        $receptionistKeys = $roles['receptionist']->permissions->pluck('key');
        $staffKeys = $roles['staff']->permissions->pluck('key');
        $managerKeys = $roles['manager']->permissions->pluck('key');
        $this->assertTrue($receptionistKeys->diff($staffKeys)->isEmpty());
        $this->assertTrue($staffKeys->diff($managerKeys)->isEmpty());
    }

    public function test_owner_can_create_edit_and_disable_a_staff_member_end_to_end(): void
    {
        $owner = $this->owner();
        $role = Role::whereNull('owner_id')->where('key', 'manager')->firstOrFail();
        $permissionIds = $role->permissions->pluck('id')->all();

        $create = $this->actingAs($owner, 'owner')->post('/staff', [
            'name' => 'New Hire', 'email' => 'newhire@t.local', 'password' => 'secret123',
            'role_id' => $role->id, 'permissions' => $permissionIds,
        ]);
        $create->assertRedirect('/staff');

        $staff = Staff::where('email', 'newhire@t.local')->firstOrFail();
        $this->assertEquals($owner->id, $staff->owner_id);
        $this->assertTrue($staff->hasPermission('sales.view'));

        $update = $this->actingAs($owner, 'owner')->put("/staff/{$staff->id}", [
            'name' => 'New Hire Updated', 'email' => 'newhire@t.local',
            'role_id' => $role->id, 'permissions' => $permissionIds,
        ]);
        $update->assertRedirect('/staff');
        $this->assertEquals('New Hire Updated', $staff->fresh()->name);

        $toggle = $this->actingAs($owner, 'owner')->post("/staff/{$staff->id}/toggle-status");
        $toggle->assertRedirect();
        $this->assertFalse($staff->fresh()->is_active);
    }

    public function test_a_disabled_staff_created_booking_stays_intact_after_the_staff_account_is_removed(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $staff = $this->staff($owner);
        $booking = $this->booking($owner, $room, $member);

        $this->actingAs($owner, 'owner')->delete("/staff/{$staff->id}")->assertRedirect('/staff');

        // The booking itself is untouched — deleting staff never cascades
        // into the tenant's actual business records.
        $this->assertNotNull($booking->fresh());
        $this->assertEquals($owner->id, $booking->fresh()->owner_id);
    }
}
