<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\HotspotUser;
use App\Models\InventoryMovement;
use App\Models\MemberPackage;
use App\Models\Owner;
use App\Models\PackageUsage;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Room;
use App\Models\Sale;
use App\Models\SharedSession;
use App\Models\Staff;
use App\Models\Workspace;
use App\Services\AnalyticsPeriod;
use App\Services\AvailabilityService;
use App\Services\HourPackageService;
use App\Services\RevenueAnalyticsService;
use App\Services\SalesService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delete Booking: full financial/operational reversal (revenue, payment,
 * Member Hour Package hours, product inventory, coupon usage), the
 * checked_in active-session guard, transaction rollback, idempotency, and
 * tenant isolation.
 */
class BookingDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function owner(array $features = ['workspace', 'booking', 'sales']): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0,
            'is_active' => true, 'sort_order' => 1, 'features' => $features,
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $type = 'meeting'): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room '.uniqid(),
            'type' => $type, 'capacity' => $type === 'shared' ? 4 : 1, 'price_per_hour' => 100,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
    }

    private function booking(Owner $owner, Room $room, HotspotUser $member, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
            'price_per_hour' => 100, 'total_hours' => 1, 'total_price' => 100,
            'amount_paid' => 0, 'payment_status' => 'unpaid', 'status' => 'confirmed',
        ], $overrides));
    }

    private function staff(Owner $owner, string $roleKey): Staff
    {
        $role = Role::whereNull('owner_id')->where('key', $roleKey)->firstOrFail();
        $staff = Staff::create([
            'owner_id' => $owner->id, 'role_id' => $role->id,
            'name' => 'Staffer', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true,
        ]);
        $staff->syncPermissionsFromRole();

        return $staff;
    }

    /** 30h package, assigned through the real endpoint (snapshots terms correctly). */
    private function package(Owner $owner, HotspotUser $member, array $overrides = []): MemberPackage
    {
        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/packages", array_merge([
            'name' => '30 Hours', 'hours' => 30, 'price_paid' => 1500,
            'starts_on' => today()->toDateString(), 'expires_on' => today()->addDays(29)->toDateString(),
        ], $overrides))->assertRedirect();

        return MemberPackage::where('owner_id', $owner->id)->where('hotspot_user_id', $member->id)->latest('id')->firstOrFail();
    }

    // --- Basic deletion + authorization ---

    public function test_owner_can_delete_a_pending_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member, ['status' => 'pending']);

        $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}")
            ->assertRedirect('/bookings')
            ->assertSessionHas('success');

        $this->assertNull(Booking::find($booking->id));
    }

    public function test_manager_can_delete_but_staff_without_the_permission_cannot(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);

        $staffBooking = $this->booking($owner, $room, $member);
        $staff = $this->staff($owner, 'staff'); // has bookings.cancel, not bookings.delete
        $this->actingAs($staff, 'staff')->delete("/bookings/{$staffBooking->id}")
            ->assertSessionHas('permission_denied');
        $this->assertNotNull(Booking::find($staffBooking->id));

        $managerBooking = $this->booking($owner, $room, $member);
        $manager = $this->staff($owner, 'manager'); // has bookings.delete
        $this->actingAs($manager, 'staff')->delete("/bookings/{$managerBooking->id}")
            ->assertSessionHas('success');
        $this->assertNull(Booking::find($managerBooking->id));
    }

    // --- Revenue ---

    public function test_deleting_a_completed_booking_removes_it_from_revenue(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member, ['status' => 'completed', 'amount_paid' => 100, 'payment_status' => 'paid']);

        $revenue = app(RevenueAnalyticsService::class);
        $period = AnalyticsPeriod::today();
        $this->assertSame(100.0, $revenue->bookingRevenue($owner, $period));

        $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}")->assertSessionHas('success');

        $this->assertSame(0.0, $revenue->bookingRevenue($owner->fresh(), $period));
    }

    // --- Package hours ---

    public function test_deleting_a_package_paid_booking_restores_its_hours(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $pkg = $this->package($owner, $member);

        $this->actingAs($owner, 'owner')->postJson('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00', 'end_time' => '11:00',
            'member_package_id' => $pkg->id,
        ])->assertOk();

        $booking = Booking::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame(120, $pkg->fresh()->used_minutes);

        $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}")->assertSessionHas('success');

        $this->assertSame(0, $pkg->fresh()->used_minutes);
        $actions = PackageUsage::where('member_package_id', $pkg->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame([PackageUsage::PURCHASE, PackageUsage::BOOKING_USAGE, PackageUsage::BOOKING_CANCELLATION], $actions);
    }

    // --- Inventory ---

    public function test_deleting_a_booking_restores_tracked_stock_and_records_a_movement_but_skips_services(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        // Items are added while still confirmed (the invoice-editability
        // guard blocks adding to an already-completed booking) and the
        // booking is finalized afterward, same as the real check-out flow.
        $booking = $this->booking($owner, $room, $member, ['status' => 'confirmed']);

        $coffee = Product::create(['owner_id' => $owner->id, 'name' => 'Coffee', 'type' => 'product', 'price' => 20, 'purchase_price' => 5, 'track_stock' => true, 'stock_quantity' => 20, 'is_active' => true]);
        $massage = Product::create(['owner_id' => $owner->id, 'name' => 'Massage', 'type' => 'service', 'price' => 50, 'is_active' => true, 'track_stock' => false]);

        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $coffee->id, 'quantity' => 2])->assertRedirect();
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $massage->id, 'quantity' => 1])->assertRedirect();

        $booking->update(['status' => 'completed', 'amount_paid' => 100, 'payment_status' => 'paid']);

        $this->assertSame(18, $coffee->fresh()->stock_quantity);
        $sale = Sale::where('booking_id', $booking->id)->firstOrFail();

        $revenue = app(RevenueAnalyticsService::class);
        $period = AnalyticsPeriod::today();
        $this->assertSame((float) $sale->total, $revenue->saleRevenue($owner, $period));

        $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}")->assertSessionHas('success');

        $this->assertSame(20, $coffee->fresh()->stock_quantity);
        $this->assertSame(1, InventoryMovement::where('product_id', $coffee->id)->where('type', 'sale_removed')->count());
        $this->assertSame(0, InventoryMovement::where('product_id', $massage->id)->count());
        $this->assertNull(Sale::find($sale->id));
        $this->assertSame(0.0, $revenue->saleRevenue($owner->fresh(), $period));
    }

    // --- Transaction rollback ---

    public function test_a_failure_partway_through_rolls_back_every_reversal(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $pkg = $this->package($owner, $member);
        $booking = $this->booking($owner, $room, $member, [
            'status' => 'confirmed', 'payment_method' => Booking::METHOD_PACKAGE, 'member_package_id' => $pkg->id,
        ]);
        app(HourPackageService::class)->reconcileBooking($booking, $pkg, 60);

        $coffee = Product::create(['owner_id' => $owner->id, 'name' => 'Coffee', 'type' => 'product', 'price' => 20, 'purchase_price' => 5, 'track_stock' => true, 'stock_quantity' => 20, 'is_active' => true]);
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $coffee->id, 'quantity' => 1])->assertRedirect();
        $sale = Sale::where('booking_id', $booking->id)->firstOrFail();
        $booking->update(['status' => 'completed']);

        $this->mock(SalesService::class, function ($mock) {
            $mock->shouldReceive('removeItem')->once()->andThrow(new \RuntimeException('simulated failure'));
        });

        $response = $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}");
        $response->assertSessionHas('error', __('app.booking.delete_failed'));

        $this->assertNotNull(Booking::find($booking->id));
        $this->assertNotNull(Sale::find($sale->id));
        $this->assertSame(19, $coffee->fresh()->stock_quantity, 'stock restore must have rolled back too');
        $this->assertSame(60, $pkg->fresh()->used_minutes, 'package release must have rolled back too');
    }

    // --- Coupons ---

    public function test_deleting_a_completed_coupon_booking_reverses_its_usage(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);
        $coupon = Coupon::create([
            'owner_id' => $owner->id, 'code' => 'SAVE20', 'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 20, 'applies_to' => Coupon::SCOPE_ROOMS, 'is_active' => true, 'usage_limit' => 1,
        ]);
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'SAVE20'])->assertRedirect();
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/status", ['status' => 'completed'])->assertSessionHasNoErrors();

        $this->assertSame(1, CouponUsage::where('booking_id', $booking->id)->count());

        $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}")->assertSessionHas('success');

        $this->assertSame(0, CouponUsage::where('coupon_id', $coupon->id)->count());

        // The single-use coupon can be redeemed again now that the usage is gone.
        $second = $this->booking($owner, $room, $member);
        $this->actingAs($owner, 'owner')->post("/bookings/{$second->id}/coupon", ['code' => 'SAVE20'])->assertRedirect();
        $this->actingAs($owner, 'owner')->post("/bookings/{$second->id}/status", ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame(1, CouponUsage::where('coupon_id', $coupon->id)->count());
    }

    // --- Availability ---

    public function test_deleting_a_confirmed_booking_frees_its_slot(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);

        $availability = app(AvailabilityService::class);
        $this->assertSame(0, $availability->availabilityForRange($room, today()->toDateString(), '10:00', '11:00'));

        $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}")->assertSessionHas('success');

        $this->assertSame(1, $availability->availabilityForRange($room->fresh(), today()->toDateString(), '10:00', '11:00'));
    }

    // --- Active-session guard ---

    public function test_a_checked_in_booking_cannot_be_deleted(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared');
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member, ['party_size' => 1, 'start_time' => now()->format('H:i'), 'end_time' => now()->addHour()->format('H:i')]);

        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/check-in", ['party_size' => 1])->assertRedirect();
        $booking->refresh();
        $this->assertSame('checked_in', $booking->status);

        $response = $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}");

        $response->assertSessionHas('error', __('app.booking.delete_disabled_checked_in'));
        $this->assertNotNull(Booking::find($booking->id));
        $this->assertSame(1, SharedSession::where('booking_id', $booking->id)->where('status', 'open')->count());
    }

    public function test_an_open_session_booking_cannot_be_deleted(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting');
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member, ['status' => 'open', 'end_time' => null]);

        $response = $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}");

        $response->assertSessionHas('error', __('app.booking.duration_type.delete_disabled_open'));
        $this->assertNotNull(Booking::find($booking->id));
    }

    // --- Idempotency ---

    public function test_a_second_delete_request_is_a_safe_no_op(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $pkg = $this->package($owner, $member);
        $booking = $this->booking($owner, $room, $member, ['payment_method' => Booking::METHOD_PACKAGE, 'member_package_id' => $pkg->id]);
        app(HourPackageService::class)->reconcileBooking($booking, $pkg, 60);

        $this->assertSame(60, $pkg->fresh()->used_minutes);

        $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}")->assertSessionHas('success');
        $this->assertSame(0, $pkg->fresh()->used_minutes);

        // The booking row is now fully gone — a second request 404s via the
        // owner-scoped findOrFail (the "already handled" friendly message is
        // for the narrower race where the outer lookup succeeds but the row
        // vanishes between it and the inner locked re-check). Either way,
        // nothing gets double-reversed.
        $this->actingAs($owner, 'owner')->delete("/bookings/{$booking->id}")->assertNotFound();
        $this->assertSame(0, $pkg->fresh()->used_minutes);
    }

    // --- Tenancy ---

    public function test_owner_cannot_delete_another_owners_booking(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member);

        $this->actingAs($intruder, 'owner')->delete("/bookings/{$booking->id}")->assertNotFound();

        $this->assertNotNull(Booking::find($booking->id));
    }

    // --- UI ---

    public function test_delete_button_is_hidden_without_permission_and_disabled_message_shown_when_checked_in(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared');
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member, ['start_time' => now()->format('H:i'), 'end_time' => now()->addHour()->format('H:i')]);

        $staff = $this->staff($owner, 'staff');
        $asStaff = $this->actingAs($staff, 'staff')->get("/bookings/{$booking->id}")->assertOk();
        $this->assertFalse($asStaff->inertiaProps('canDelete')); // the React page renders no delete button

        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/check-in", ['party_size' => 1])->assertRedirect();
        $asOwner = $this->actingAs($owner, 'owner')->get("/bookings/{$booking->id}")->assertOk();
        // checked_in → the page shows booking.delete_disabled_checked_in instead of the button.
        $this->assertSame('checked_in', $asOwner->inertiaProps('booking.status'));
        $this->assertStringContainsString("t('booking.delete_disabled_checked_in')", file_get_contents(resource_path('js/Pages/Bookings/Show.jsx')));
    }
}
