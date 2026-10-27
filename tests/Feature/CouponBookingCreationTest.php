<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\HotspotUser;
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

/**
 * Coupons entered during booking creation — the read-only coupon-preview
 * endpoint (/bookings/coupon-preview) and the store() integration (optional
 * coupon_code applied via CouponService::attachToBooking() inside the same
 * transaction). Mirrors CouponBookingCheckoutTest's fixtures/conventions.
 */
class CouponBookingCreationTest extends TestCase
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

    private function room(Owner $owner, string $type = 'meeting'): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room A',
            'type' => $type, 'capacity' => $type === 'shared' ? 4 : 1, 'price_per_hour' => 100,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '01'.rand(100000000, 999999999),
            'password' => 'x', 'status' => 'active',
        ]);
    }

    private function coupon(Owner $owner, array $attrs = []): Coupon
    {
        return Coupon::create(array_merge([
            'owner_id' => $owner->id,
            'code' => 'SAVE20',
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 20,
            'applies_to' => Coupon::SCOPE_ROOMS,
            'is_active' => true,
        ], $attrs));
    }

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

    private function previewParams(Room $room, string $code, array $extra = []): array
    {
        return array_merge([
            'coupon_code' => $code,
            'room_id' => $room->id,
            'booking_date' => today()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
        ], $extra);
    }

    private function preview(Owner $owner, array $params)
    {
        return $this->asGuard($owner, 'owner')
            ->getJson('/bookings/coupon-preview?'.http_build_query($params));
    }

    private function createParams(Room $room, HotspotUser $member, array $extra = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'hotspot_user_id' => $member->id,
            'booking_date' => today()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'payment_choice' => 'none',
        ], $extra);
    }

    // =========================== Preview endpoint ===========================

    public function test_preview_shows_the_discount_for_a_valid_percentage_coupon(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->coupon($owner, ['discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 20]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertOk();
        $response->assertJsonPath("coupon.discount", 20);
        $response->assertJsonPath("coupon.total", 80);
        $response->assertJsonPath('coupon_error', null);
    }

    public function test_preview_shows_the_discount_for_a_valid_fixed_coupon(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->coupon($owner, ['discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 15]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertOk();
        $response->assertJsonPath("coupon.discount", 15);
        $response->assertJsonPath("coupon.total", 85);
    }

    public function test_preview_rejects_an_unknown_code(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        $response = $this->preview($owner, $this->previewParams($room, 'NOPE'));

        $response->assertOk();
        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_an_inactive_coupon(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->coupon($owner, ['is_active' => false]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_a_scheduled_coupon(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->coupon($owner, ['starts_at' => now()->addDay()]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_an_expired_coupon(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->coupon($owner, ['expires_at' => now()->subDay()]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_below_minimum_spend(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->coupon($owner, ['minimum_spend' => 500]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_a_coupon_scoped_to_a_different_room(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $otherRoom = $this->room($owner);
        $coupon = $this->coupon($owner);
        $coupon->rooms()->attach($otherRoom->id);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_once_the_global_usage_limit_is_reached(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $coupon = $this->coupon($owner, ['usage_limit' => 1]);
        CouponUsage::create([
            'coupon_id' => $coupon->id, 'owner_id' => $owner->id, 'booking_id' => null,
            'hotspot_user_id' => $member->id, 'original_amount' => 100, 'discount_amount' => 20,
            'final_amount' => 80, 'room_discount' => 20, 'product_discount' => 0, 'used_at' => now(),
        ]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_once_this_customer_has_already_used_it(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $coupon = $this->coupon($owner, ['per_customer_limit' => 1]);
        CouponUsage::create([
            'coupon_id' => $coupon->id, 'owner_id' => $owner->id, 'booking_id' => null,
            'hotspot_user_id' => $member->id, 'original_amount' => 100, 'discount_amount' => 20,
            'final_amount' => 80, 'room_discount' => 20, 'product_discount' => 0, 'used_at' => now(),
        ]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20', ['hotspot_user_id' => $member->id]));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_a_shared_room(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared');
        $this->coupon($owner, ['applies_to' => Coupon::SCOPE_BOTH]);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20', ['party_size' => 2]));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_rejects_a_package_paid_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->coupon($owner);

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20', ['member_package_id' => 999]));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_requires_the_minimum_booking_context(): void
    {
        $owner = $this->owner();

        $response = $this->asGuard($owner, 'owner')->getJson('/bookings/coupon-preview?coupon_code=SAVE20');

        $response->assertStatus(422);
    }

    public function test_preview_rejects_a_room_from_another_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $otherRoom = $this->room($other);
        $this->coupon($owner);

        $response = $this->preview($owner, $this->previewParams($otherRoom, 'SAVE20'));

        $response->assertStatus(404);
    }

    public function test_preview_cannot_see_another_owners_coupon(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $room = $this->room($owner);
        $this->coupon($other); // belongs to a different tenant

        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20'));

        $response->assertJsonPath('coupon', null);
        $this->assertNotNull($response->json('coupon_error'));
    }

    public function test_preview_writes_nothing_and_creates_no_redemption(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->coupon($owner);

        $this->preview($owner, $this->previewParams($room, 'SAVE20'))->assertOk();

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, CouponUsage::count());
    }

    public function test_preview_prices_from_the_server_not_the_client(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner); // 100/hr, server-side
        $this->coupon($owner, ['discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 15]);

        // A client-supplied "total_price"/"subtotal" is simply not a
        // recognised field — the server quotes the room itself.
        $response = $this->preview($owner, $this->previewParams($room, 'SAVE20', ['total_price' => 1, 'subtotal' => 1]));

        $response->assertJsonPath('coupon.subtotal', 100);
        $response->assertJsonPath("coupon.total", 85);
    }

    // =========================== Booking creation ===========================

    public function test_creating_a_booking_without_a_coupon_is_unchanged(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);

        $this->asGuard($owner, 'owner')->post('/bookings', $this->createParams($room, $member))->assertRedirect();

        $booking = Booking::first();
        $this->assertNotNull($booking);
        $this->assertNull($booking->coupon_id);
        $this->assertSame(0.0, (float) $booking->discount_total);
        $this->assertSame(100.0, (float) $booking->total_price);
    }

    public function test_creating_a_booking_with_a_valid_coupon_stores_the_discount(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $coupon = $this->coupon($owner, ['discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 20]);

        $this->asGuard($owner, 'owner')
            ->post('/bookings', $this->createParams($room, $member, ['coupon_code' => 'save20']))
            ->assertRedirect();

        $booking = Booking::first();
        $this->assertNotNull($booking);
        $this->assertSame($coupon->id, $booking->coupon_id);
        $this->assertSame(20.0, (float) $booking->discount_total);
        $this->assertSame(100.0, (float) $booking->total_price);
        $this->assertSame(80.0, $booking->netRoomCharge());
        // Attaching is not redeeming — no usage row until completion.
        $this->assertSame(0, CouponUsage::count());
    }

    public function test_an_invalid_coupon_at_creation_fails_and_creates_no_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);

        $response = $this->asGuard($owner, 'owner')
            ->post('/bookings', $this->createParams($room, $member, ['coupon_code' => 'NOPE']));

        $response->assertSessionHas('error');
        $this->assertSame(0, Booking::count());
    }

    public function test_an_exhausted_coupon_at_creation_fails_and_creates_no_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $coupon = $this->coupon($owner, ['usage_limit' => 1]);
        // A real prior booking, not a null booking_id: evaluate()'s exclude
        // filter ("booking_id != :this one") would otherwise silently drop a
        // null-booking_id row under SQL's NULL-comparison semantics, hiding
        // it from the very usage count this test means to exhaust.
        $priorBooking = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(), 'start_time' => '08:00', 'end_time' => '09:00',
            'price_per_hour' => 100, 'total_hours' => 1, 'total_price' => 100,
            'amount_paid' => 0, 'payment_status' => 'unpaid', 'status' => 'completed',
        ]);
        CouponUsage::create([
            'coupon_id' => $coupon->id, 'owner_id' => $owner->id, 'booking_id' => $priorBooking->id,
            'hotspot_user_id' => $member->id, 'original_amount' => 100, 'discount_amount' => 20,
            'final_amount' => 80, 'room_discount' => 20, 'product_discount' => 0, 'used_at' => now(),
        ]);

        $response = $this->asGuard($owner, 'owner')
            ->post('/bookings', $this->createParams($room, $member, ['coupon_code' => 'SAVE20']));

        $response->assertSessionHas('error');
        $this->assertSame(1, Booking::count()); // only the pre-existing one — nothing new was created
    }

    public function test_a_coupon_that_would_exceed_the_amount_already_paid_fails_and_creates_no_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $this->coupon($owner, ['discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 20]);

        // Paying the full 100 up front, then asking for a 20% discount:
        // the net (80) can no longer cover what was already collected.
        $response = $this->asGuard($owner, 'owner')
            ->post('/bookings', $this->createParams($room, $member, [
                'coupon_code' => 'SAVE20', 'payment_choice' => 'full',
            ]));

        $response->assertSessionHas('error');
        $this->assertSame(0, Booking::count());
    }

    public function test_a_coupon_cannot_be_used_to_create_a_shared_room_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared');
        $member = $this->member($owner);
        $this->coupon($owner, ['applies_to' => Coupon::SCOPE_BOTH]);

        $response = $this->asGuard($owner, 'owner')
            ->post('/bookings', $this->createParams($room, $member, [
                'coupon_code' => 'SAVE20', 'party_size' => 2,
            ]));

        $response->assertSessionHas('error');
        $this->assertSame(0, Booking::count());
    }

    public function test_staff_without_coupon_permission_cannot_create_a_booking_with_a_coupon(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $this->coupon($owner);
        // Receptionist has coupons.apply by default — strip just that one,
        // keeping bookings.create so the request reaches the controller.
        $staff = $this->staff($owner);
        $staff->permissions()->detach(\App\Models\Permission::where('key', 'coupons.apply')->first());

        $response = $this->asGuard($staff, 'staff')
            ->post('/bookings', $this->createParams($room, $member, ['coupon_code' => 'SAVE20']));

        $response->assertSessionHas('error');
        $this->assertSame(0, Booking::count());
    }

    public function test_completing_a_booking_created_with_a_coupon_records_exactly_one_redemption(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $this->coupon($owner, ['discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 20]);

        $this->asGuard($owner, 'owner')
            ->post('/bookings', $this->createParams($room, $member, ['coupon_code' => 'SAVE20']))
            ->assertRedirect();
        $booking = Booking::first();

        $this->asGuard($owner, 'owner')
            ->post("/bookings/{$booking->id}/status", ['status' => 'completed'])
            ->assertRedirect();

        $this->assertSame(1, CouponUsage::where('booking_id', $booking->id)->count());
        $usage = CouponUsage::where('booking_id', $booking->id)->first();
        $this->assertSame(100.0, (float) $usage->original_amount);
        $this->assertSame(20.0, (float) $usage->discount_amount);
        $this->assertSame(80.0, (float) $usage->final_amount);
    }
}
