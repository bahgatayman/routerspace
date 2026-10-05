<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponBookingCheckoutTest extends TestCase
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

    private function booking(Owner $owner, Room $room, HotspotUser $member, string $status = 'confirmed'): Booking
    {
        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
            'price_per_hour' => 100, 'total_hours' => 1, 'total_price' => 100,
            'amount_paid' => 0, 'payment_status' => 'unpaid',
            'status' => $status,
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

    // --- Apply / remove ---

    public function test_owner_can_apply_and_remove_a_coupon_on_a_confirmed_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $this->coupon($owner);

        $this->asGuard($owner, 'owner')
            ->post("/bookings/{$booking->id}/coupon", ['code' => 'save20'])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(100.0, (float) $booking->total_price);
        $this->assertSame(20.0, (float) $booking->discount_total);
        $this->assertSame(80.0, $booking->netRoomCharge());

        $this->asGuard($owner, 'owner')->delete("/bookings/{$booking->id}/coupon")->assertRedirect();
        $booking->refresh();
        $this->assertSame(0.0, (float) $booking->discount_total);
        $this->assertNull($booking->coupon_id);
    }

    public function test_an_invalid_code_shows_a_friendly_error_and_changes_nothing(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);

        $response = $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'NOPE']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0.0, (float) $booking->fresh()->discount_total);
    }

    public function test_a_coupon_cannot_be_attached_to_a_shared_room_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared');
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $this->coupon($owner, ['applies_to' => Coupon::SCOPE_BOTH]);

        $response = $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20']);

        $response->assertSessionHas('error');
        $this->assertNull($booking->fresh()->coupon_id);
    }

    // --- Completion / usage recording ---

    public function test_completing_a_booking_records_exactly_one_usage_and_updates_payment_fields(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $this->coupon($owner);

        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20']);

        $this->asGuard($owner, 'owner')
            ->post("/bookings/{$booking->id}/status", ['status' => 'completed'])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame('completed', $booking->status);
        $this->assertSame(1, CouponUsage::where('booking_id', $booking->id)->count());

        $usage = CouponUsage::where('booking_id', $booking->id)->first();
        $this->assertSame(100.0, (float) $usage->original_amount);
        $this->assertSame(20.0, (float) $usage->discount_amount);
        $this->assertSame(80.0, (float) $usage->final_amount);
        $this->assertSame($member->id, $usage->hotspot_user_id);

        $this->assertSame(80.0, $booking->balanceDue()); // nothing paid yet, net charge is 80
    }

    public function test_cancelling_or_merely_attaching_a_coupon_records_no_usage(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $this->coupon($owner);

        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20']);
        $this->assertSame(0, CouponUsage::count());

        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/status", ['status' => 'cancelled']);
        $this->assertSame(0, CouponUsage::count());
    }

    public function test_completing_twice_never_creates_a_second_usage_row(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $this->coupon($owner);
        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20']);

        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/status", ['status' => 'completed']);
        $second = $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/status", ['status' => 'completed']);

        $second->assertSessionHas('error'); // terminal state — no valid transition out of completed
        $this->assertSame(1, CouponUsage::where('booking_id', $booking->id)->count());
    }

    // --- Usage limits ---

    public function test_usage_limit_of_one_lets_only_the_first_of_two_sequential_completions_succeed(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $coupon = $this->coupon($owner, ['usage_limit' => 1]);

        $bookingA = $this->booking($owner, $room, $member);
        $bookingB = $this->booking($owner, $room, $member);
        $this->asGuard($owner, 'owner')->post("/bookings/{$bookingA->id}/coupon", ['code' => 'SAVE20']);
        $this->asGuard($owner, 'owner')->post("/bookings/{$bookingB->id}/coupon", ['code' => 'SAVE20']);

        $first = $this->asGuard($owner, 'owner')->post("/bookings/{$bookingA->id}/status", ['status' => 'completed']);
        $first->assertSessionHasNoErrors();
        $this->assertSame('completed', $bookingA->fresh()->status);

        $second = $this->asGuard($owner, 'owner')->post("/bookings/{$bookingB->id}/status", ['status' => 'completed']);
        $second->assertSessionHas('error');
        $bookingB->refresh();
        $this->assertSame('confirmed', $bookingB->status); // rolled back — never silently completed
        $this->assertSame(1, $coupon->usages()->count());
    }

    public function test_per_customer_limit_of_one_blocks_the_same_member_but_not_a_different_one(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $memberA = $this->member($owner);
        $memberB = $this->member($owner);
        $this->coupon($owner, ['per_customer_limit' => 1]);

        $bookingA1 = $this->booking($owner, $room, $memberA);
        $bookingA2 = $this->booking($owner, $room, $memberA);
        $bookingB = $this->booking($owner, $room, $memberB);

        foreach ([$bookingA1, $bookingA2, $bookingB] as $b) {
            $this->asGuard($owner, 'owner')->post("/bookings/{$b->id}/coupon", ['code' => 'SAVE20']);
        }

        $this->asGuard($owner, 'owner')->post("/bookings/{$bookingA1->id}/status", ['status' => 'completed']);
        $this->assertSame('completed', $bookingA1->fresh()->status);

        $blocked = $this->asGuard($owner, 'owner')->post("/bookings/{$bookingA2->id}/status", ['status' => 'completed']);
        $blocked->assertSessionHas('error');
        $this->assertSame('confirmed', $bookingA2->fresh()->status);

        $this->asGuard($owner, 'owner')->post("/bookings/{$bookingB->id}/status", ['status' => 'completed']);
        $this->assertSame('completed', $bookingB->fresh()->status);
    }

    // --- Product-side sync ---

    public function test_adding_and_removing_products_resyncs_the_coupons_product_discount(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $product = Product::create(['owner_id' => $owner->id, 'name' => 'Coffee', 'type' => 'product', 'price' => 50, 'is_active' => true, 'track_stock' => false]);
        $this->coupon($owner, ['applies_to' => Coupon::SCOPE_BOTH]);
        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20']);

        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 1]);

        $booking->refresh()->load('sale');
        // Subtotal 150 (100 room + 50 product), 20% both => 30 total discount,
        // split proportionally: room 20, product 10.
        $this->assertSame(20.0, (float) $booking->discount_total);
        $this->assertSame(10.0, (float) $booking->sale->discount_total);
        $this->assertSame(40.0, (float) $booking->sale->total); // 50 - 10

        $item = $booking->sale->items()->first();
        $this->asGuard($owner, 'owner')->delete("/bookings/{$booking->id}/items/{$item->id}");

        $booking->refresh();
        // Back to room-only eligible: full 20% of 100 = 20 room discount.
        $this->assertSame(20.0, (float) $booking->discount_total);
    }

    public function test_changing_a_products_quantity_resyncs_the_coupons_product_discount(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $product = Product::create(['owner_id' => $owner->id, 'name' => 'Coffee', 'type' => 'product', 'price' => 50, 'is_active' => true, 'track_stock' => false]);
        $this->coupon($owner, ['applies_to' => Coupon::SCOPE_BOTH]);
        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20']);
        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 1]);

        $item = $booking->fresh('sale')->sale->items()->first();
        $this->asGuard($owner, 'owner')->patch("/bookings/{$booking->id}/items/{$item->id}", ['quantity' => 3]);

        $booking->refresh();
        // Subtotal now 250 (100 room + 150 product), 20% both => 50 total
        // discount, split proportionally: room 20, product 30.
        $this->assertSame(20.0, (float) $booking->discount_total);
        $this->assertSame(30.0, (float) $booking->sale->discount_total);
        $this->assertSame(120.0, (float) $booking->sale->total); // 150 - 30
    }

    // --- Tenancy ---

    public function test_owner_cannot_apply_another_owners_coupon(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $this->coupon($intruder, ['code' => 'INTRUDER']);

        $response = $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'INTRUDER']);

        $response->assertSessionHas('error');
        $this->assertNull($booking->fresh()->coupon_id);
    }

    // --- Staff permission gating ---

    public function test_staff_without_coupons_apply_is_denied(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $this->coupon($owner);

        $staff = $this->staff($owner, 'receptionist');
        $staff->permissions()->detach(); // strip the default coupons.apply grant

        $response = $this->asGuard($staff, 'staff')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20']);

        $response->assertRedirect();
        $response->assertSessionHas('permission_denied');
    }

    // --- The scheduled auto-completion sweep ---

    public function test_the_sweep_redeems_a_valid_coupon_when_auto_completing(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $booking->update(['booking_date' => today()->subDay()]); // unconditionally "ended"
        $this->coupon($owner);
        $this->asGuard($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20']);

        $this->artisan('bookings:complete-expired')->assertExitCode(0);

        $booking->refresh();
        $this->assertSame('completed', $booking->status);
        $this->assertSame(20.0, (float) $booking->discount_total);
        $this->assertSame(1, CouponUsage::where('booking_id', $booking->id)->count());
    }

    public function test_the_sweep_drops_an_invalid_coupon_and_completes_the_booking_anyway(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $coupon = $this->coupon($owner, ['usage_limit' => 1]);

        $bookingA = $this->booking($owner, $room, $member);
        $bookingB = $this->booking($owner, $room, $member);
        $bookingA->update(['booking_date' => today()->subDay()]);
        $bookingB->update(['booking_date' => today()->subDay()]);
        $this->asGuard($owner, 'owner')->post("/bookings/{$bookingA->id}/coupon", ['code' => 'SAVE20']);
        $this->asGuard($owner, 'owner')->post("/bookings/{$bookingB->id}/coupon", ['code' => 'SAVE20']);

        $this->artisan('bookings:complete-expired')->assertExitCode(0);

        // Both complete — the sweep never leaves one stuck — but only one
        // could actually redeem the single-use coupon; the other had it
        // silently dropped.
        $bookingA->refresh();
        $bookingB->refresh();
        $this->assertSame('completed', $bookingA->status);
        $this->assertSame('completed', $bookingB->status);
        $this->assertSame(1, $coupon->usages()->count());

        $withoutCoupon = collect([$bookingA, $bookingB])->first(fn ($b) => $b->coupon_id === null);
        $this->assertNotNull($withoutCoupon);
        $this->assertSame(0.0, (float) $withoutCoupon->discount_total);
    }
}
