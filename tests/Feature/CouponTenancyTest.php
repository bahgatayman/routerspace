<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner-vs-owner isolation for the Coupons CRUD surface itself (checkout-time
 * isolation — applying another owner's code — is covered in
 * CouponBookingCheckoutTest/CouponSharedSessionCloseTest).
 */
class CouponTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
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
            'type' => 'meeting', 'capacity' => 1, 'price_per_hour' => 100,
        ]);
    }

    private function product(Owner $owner): Product
    {
        return Product::create(['owner_id' => $owner->id, 'name' => 'Coffee', 'type' => 'product', 'price' => 25, 'is_active' => true]);
    }

    private function coupon(Owner $owner, array $attrs = []): Coupon
    {
        return Coupon::create(array_merge([
            'owner_id' => $owner->id,
            'code' => 'MINE'.uniqid(),
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'applies_to' => Coupon::SCOPE_ROOMS,
            'is_active' => true,
        ], $attrs));
    }

    private function validCouponPayload(): array
    {
        return [
            'code' => 'NEWCODE'.uniqid(),
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 15,
            'applies_to' => Coupon::SCOPE_ROOMS,
            'room_scope' => 'all',
            'is_active' => '1',
        ];
    }

    public function test_owner_cannot_view_edit_toggle_or_delete_another_owners_coupon(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $coupon = $this->coupon($intruder);

        $this->actingAs($owner, 'owner')->get("/coupons/{$coupon->id}/edit")->assertNotFound();
        $this->actingAs($owner, 'owner')->put("/coupons/{$coupon->id}", $this->validCouponPayload())->assertNotFound();
        $this->actingAs($owner, 'owner')->post("/coupons/{$coupon->id}/toggle")->assertNotFound();
        $this->actingAs($owner, 'owner')->delete("/coupons/{$coupon->id}")->assertNotFound();

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'owner_id' => $intruder->id]);
    }

    public function test_owner_cannot_target_another_owners_room_or_product_when_creating_a_coupon(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $foreignRoom = $this->room($intruder);
        $foreignProduct = $this->product($intruder);

        $response = $this->actingAs($owner, 'owner')->post('/coupons', [
            'code' => 'HACK'.uniqid(),
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'applies_to' => Coupon::SCOPE_BOTH,
            'room_scope' => 'specific',
            'room_ids' => [$foreignRoom->id],
            'product_scope' => 'specific',
            'product_ids' => [$foreignProduct->id],
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors(['room_ids.0', 'product_ids.0']);
        $this->assertDatabaseMissing('coupons', ['owner_id' => $owner->id]);
    }

    public function test_owner_a_coupon_never_targets_owner_bs_room_via_the_pivot(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $coupon = $this->coupon($owner, ['applies_to' => Coupon::SCOPE_ROOMS]);

        $this->actingAs($owner, 'owner')->put("/coupons/{$coupon->id}", [
            'code' => $coupon->code,
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'applies_to' => Coupon::SCOPE_ROOMS,
            'room_scope' => 'specific',
            'room_ids' => [$room->id],
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertTrue($coupon->fresh()->rooms->pluck('id')->contains($room->id));

        $other = $this->owner();
        $otherRoom = $this->room($other);
        $this->assertFalse($coupon->fresh()->rooms->pluck('id')->contains($otherRoom->id));
    }

    public function test_the_coupons_index_only_ever_lists_the_current_owners_coupons(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $this->coupon($owner, ['code' => 'MINEVISIBLE']);
        $this->coupon($intruder, ['code' => 'THEIRSHIDDEN']);

        $response = $this->actingAs($owner, 'owner')->get('/coupons');

        $response->assertOk();
        $this->assertSame(['MINEVISIBLE'], collect($response->inertiaProps('coupons.data'))->pluck('code')->all());
        // Tenant isolation: the other owner's code appears nowhere in the page props.
        $this->assertStringNotContainsString('THEIRSHIDDEN', json_encode($response->inertiaProps()));
    }
}
