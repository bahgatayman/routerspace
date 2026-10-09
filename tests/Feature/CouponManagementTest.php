<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function asGuard($user, string $guard): static
    {
        auth('owner')->logout();
        auth('staff')->logout();
        auth('admin')->logout();

        return $this->actingAs($user, $guard);
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

    private function room(Owner $owner): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room A',
            'type' => 'meeting', 'capacity' => 1, 'price_per_hour' => 100,
        ]);
    }

    private function coupon(Owner $owner, array $attrs = []): Coupon
    {
        return Coupon::create(array_merge([
            'owner_id' => $owner->id,
            'code' => 'CODE'.uniqid(),
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'applies_to' => Coupon::SCOPE_ROOMS,
            'is_active' => true,
        ], $attrs));
    }

    private function staff(Owner $owner, string $roleKey = 'manager'): Staff
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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'welcome20',
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 20,
            'applies_to' => Coupon::SCOPE_ROOMS,
            'room_scope' => 'all',
            'is_active' => '1',
        ], $overrides);
    }

    // --- Creation ---

    public function test_owner_can_create_a_valid_coupon_and_the_code_is_normalized(): void
    {
        $owner = $this->owner();

        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['code' => '  welcome20  ']))->assertRedirect();

        $this->assertDatabaseHas('coupons', ['owner_id' => $owner->id, 'code' => 'WELCOME20']);
    }

    public function test_duplicate_code_for_the_same_owner_is_rejected(): void
    {
        $owner = $this->owner();
        $this->coupon($owner, ['code' => 'DUPLICATE']);

        $response = $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['code' => 'duplicate']));

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, Coupon::where('code', 'DUPLICATE')->count());
    }

    public function test_the_same_code_is_allowed_for_a_different_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->coupon($other, ['code' => 'SHARED10']);

        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['code' => 'shared10']))->assertRedirect();

        $this->assertSame(2, Coupon::where('code', 'SHARED10')->count());
    }

    public function test_percentage_discount_must_be_between_0_and_100_exclusive_and_inclusive(): void
    {
        $owner = $this->owner();

        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['discount_value' => 0]))
            ->assertSessionHasErrors('discount_value');
        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['discount_value' => 101]))
            ->assertSessionHasErrors('discount_value');
        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['code' => 'HUNDRED', 'discount_value' => 100]))
            ->assertSessionDoesntHaveErrors('discount_value');
    }

    public function test_fixed_discount_must_be_greater_than_zero(): void
    {
        $owner = $this->owner();

        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['discount_type' => 'fixed', 'discount_value' => 0]))
            ->assertSessionHasErrors('discount_value');
        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['discount_type' => 'fixed', 'discount_value' => -5]))
            ->assertSessionHasErrors('discount_value');
    }

    public function test_expiry_before_start_is_rejected(): void
    {
        $owner = $this->owner();

        $response = $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload([
            'starts_at' => '2026-10-10', 'expires_at' => '2026-10-01',
        ]));

        $response->assertSessionHasErrors('expires_at');
    }

    public function test_negative_usage_limits_are_rejected(): void
    {
        $owner = $this->owner();

        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['usage_limit' => -1]))
            ->assertSessionHasErrors('usage_limit');
        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['per_customer_limit' => -1]))
            ->assertSessionHasErrors('per_customer_limit');
    }

    public function test_an_empty_code_is_rejected(): void
    {
        $owner = $this->owner();

        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['code' => '']))
            ->assertSessionHasErrors('code');
        $this->asGuard($owner, 'owner')->post('/coupons', $this->basePayload(['code' => '   ']))
            ->assertSessionHasErrors('code');
    }

    // --- Pivot sync ---

    public function test_switching_from_specific_to_all_clears_the_room_pivot(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $coupon = $this->coupon($owner);
        $coupon->rooms()->attach($room->id);

        $this->asGuard($owner, 'owner')->put("/coupons/{$coupon->id}", $this->basePayload(['code' => $coupon->code, 'room_scope' => 'all']));

        $this->assertSame(0, $coupon->fresh()->rooms()->count());
    }

    // --- Derived status ---

    public function test_usage_limit_reached_status_does_not_flip_is_active(): void
    {
        $owner = $this->owner();
        $coupon = $this->coupon($owner, ['usage_limit' => 1]);
        CouponUsage::create([
            'coupon_id' => $coupon->id, 'owner_id' => $owner->id,
            'original_amount' => 10, 'discount_amount' => 1, 'final_amount' => 9,
            'room_discount' => 1, 'product_discount' => 0, 'used_at' => now(),
        ]);

        $coupon->refresh();
        $this->assertTrue($coupon->is_active);
        $this->assertSame('limit_reached', $coupon->statusKey());
    }

    public function test_every_derived_status_resolves_correctly(): void
    {
        $owner = $this->owner();

        $this->assertSame('inactive', $this->coupon($owner, ['is_active' => false])->statusKey());
        $this->assertSame('expired', $this->coupon($owner, ['expires_at' => now()->subDay()])->statusKey());
        $this->assertSame('scheduled', $this->coupon($owner, ['starts_at' => now()->addDay()])->statusKey());
        $this->assertSame('active', $this->coupon($owner)->statusKey());
    }

    // --- List search / filter ---

    public function test_index_search_filters_by_code(): void
    {
        $owner = $this->owner();
        $this->coupon($owner, ['code' => 'FINDME']);
        $this->coupon($owner, ['code' => 'HIDDEN']);

        $response = $this->asGuard($owner, 'owner')->get('/coupons?search=findme');

        $this->assertSame(['FINDME'], collect($response->inertiaProps('coupons.data'))->pluck('code')->all());
        $this->assertStringNotContainsString('HIDDEN', json_encode($response->inertiaProps()));
    }

    public function test_index_status_filter_shows_only_matching_coupons(): void
    {
        $owner = $this->owner();
        $this->coupon($owner, ['code' => 'STAYSON', 'is_active' => true]);
        $this->coupon($owner, ['code' => 'TURNEDOFF', 'is_active' => false]);

        $response = $this->asGuard($owner, 'owner')->get('/coupons?status=inactive');

        $this->assertSame(['TURNEDOFF'], collect($response->inertiaProps('coupons.data'))->pluck('code')->all());
        $this->assertStringNotContainsString('STAYSON', json_encode($response->inertiaProps()));
    }

    // --- Safe delete ---

    public function test_delete_is_blocked_when_the_coupon_has_usage_history(): void
    {
        $owner = $this->owner();
        $coupon = $this->coupon($owner);
        CouponUsage::create([
            'coupon_id' => $coupon->id, 'owner_id' => $owner->id,
            'original_amount' => 10, 'discount_amount' => 1, 'final_amount' => 9,
            'room_discount' => 1, 'product_discount' => 0, 'used_at' => now(),
        ]);

        $response = $this->asGuard($owner, 'owner')->delete("/coupons/{$coupon->id}");

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    public function test_delete_succeeds_with_no_usage_history(): void
    {
        $owner = $this->owner();
        $coupon = $this->coupon($owner);

        $this->asGuard($owner, 'owner')->delete("/coupons/{$coupon->id}")->assertRedirect();

        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    // --- Toggle ---

    public function test_toggle_flips_is_active(): void
    {
        $owner = $this->owner();
        $coupon = $this->coupon($owner, ['is_active' => true]);

        $this->asGuard($owner, 'owner')->post("/coupons/{$coupon->id}/toggle")->assertRedirect();
        $this->assertFalse($coupon->fresh()->is_active);
    }

    // --- Permissions ---

    public function test_staff_without_coupons_permissions_are_denied(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner, 'receptionist'); // no coupons.view/create/edit/delete

        $this->asGuard($staff, 'staff')->get('/coupons')->assertRedirect()->assertSessionHas('permission_denied');
    }

    public function test_manager_role_has_full_coupon_access_by_default(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner, 'manager');

        $this->asGuard($staff, 'staff')->get('/coupons')->assertOk();
        $this->asGuard($staff, 'staff')->post('/coupons', $this->basePayload(['code' => 'MANAGERMADE']))->assertRedirect();
        $this->assertDatabaseHas('coupons', ['code' => 'MANAGERMADE']);
    }

    // --- Feature gate ---

    public function test_an_owner_with_neither_booking_nor_sales_cannot_reach_coupons(): void
    {
        $owner = $this->owner(['workspace']);

        $this->asGuard($owner, 'owner')->get('/coupons')->assertRedirect('/dashboard');
    }
}
