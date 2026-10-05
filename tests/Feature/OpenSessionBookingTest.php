<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\Owner;
use App\Models\PackageUsage;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomPricingProfile;
use App\Models\SaleItem;
use App\Models\Staff;
use App\Models\Workspace;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Open Session: an exclusive-room booking that starts now (server time,
 * never client-trusted) with no end time, occupies the room immediately,
 * and is priced at checkout through the same RoomPricingService/
 * SharedSessionBillingService engine Shared Sessions already use — without
 * touching SharedSession/SharedSessionController at all.
 */
class OpenSessionBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

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

    private function room(Owner $owner, array $overrides = []): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create(array_merge([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room '.uniqid(),
            'type' => 'meeting', 'capacity' => 1, 'price_per_hour' => 100,
        ], $overrides));
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
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

    private function openSession(Owner $owner, Room $room, HotspotUser $member): Booking
    {
        $this->actingAs($owner, 'owner')->post('/bookings', [
            'duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
        ])->assertRedirect();

        return Booking::where('room_id', $room->id)->where('status', 'open')->firstOrFail();
    }

    // ------------------------------------------------------------------ creation

    public function test_opening_a_session_sets_server_time_and_null_end_time_ignoring_client_values(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:15:00'));

        $this->actingAs($owner, 'owner')->post('/bookings', [
            'duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            // These must all be ignored server-side.
            'booking_date' => '2099-01-01', 'start_time' => '23:59', 'end_time' => '23:59',
        ])->assertRedirect();

        $booking = Booking::where('room_id', $room->id)->firstOrFail();
        $this->assertSame('open', $booking->status);
        $this->assertSame('2026-09-01', $booking->booking_date->format('Y-m-d'));
        $this->assertSame('10:15', substr($booking->start_time, 0, 5));
        $this->assertNull($booking->end_time);
        $this->assertSame(1, $booking->party_size);
        $this->assertEquals(0, (float) $booking->total_price);
    }

    public function test_opening_a_session_occupies_the_room_immediately_blocking_a_new_fixed_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $this->openSession($owner, $room, $member);

        $response = $this->actingAs($owner, 'owner')->postJson('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => today()->toDateString(), 'start_time' => '00:00', 'end_time' => '23:59',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Booking::where('room_id', $room->id)->count());
    }

    public function test_opening_a_session_is_rejected_when_the_room_already_has_one_open(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $this->openSession($owner, $room, $member);

        $this->actingAs($owner, 'owner')->post('/bookings', [
            'duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
        ])->assertSessionHas('error');

        $this->assertSame(1, Booking::where('room_id', $room->id)->where('status', 'open')->count());
    }

    public function test_opening_a_session_on_a_shared_room_is_rejected(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, ['type' => 'shared', 'capacity' => 8]);
        $member = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', [
            'duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
        ])->assertSessionHas('error', __('app.booking.duration_type.shared_room_not_allowed'));

        $this->assertSame(0, Booking::count());
    }

    public function test_fixed_time_booking_without_duration_type_behaves_exactly_as_before(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => today()->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertRedirect();

        $booking = Booking::where('room_id', $room->id)->firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        $this->assertNotNull($booking->end_time);
        $this->assertEquals(100, (float) $booking->total_price);
    }

    // ------------------------------------------------------------------ checkout

    public function test_closing_computes_duration_and_price_server_side_ignoring_client_submitted_values(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $booking = $this->openSession($owner, $room, $member);

        Carbon::setTestNow(Carbon::parse('2026-09-01 11:00:00'));

        $this->actingAs($owner, 'owner')
            ->postJson("/bookings/{$booking->id}/close", ['total_price' => 1, 'total_hours' => 999])
            ->assertOk()->assertJson(['success' => true]);

        $booking->refresh();
        $this->assertSame('completed', $booking->status);
        $this->assertSame('11:00', substr($booking->end_time, 0, 5));
        $this->assertEquals(100.0, (float) $booking->total_price); // 1 hour @ 100/hr
        $this->assertEquals(100.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PAID, $booking->payment_status);
    }

    public function test_closing_honors_a_pricing_profile_rate(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, ['price_per_hour' => 100]);
        $profile = RoomPricingProfile::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'name' => 'Cinema', 'price_per_hour' => 500, 'is_active' => true,
        ]);
        $member = $this->member($owner);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->actingAs($owner, 'owner')->post('/bookings', [
            'duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'room_pricing_profile_id' => $profile->id,
        ])->assertRedirect();
        $booking = Booking::where('room_id', $room->id)->firstOrFail();
        $this->assertEquals(500.0, (float) $booking->price_per_hour);
        $this->assertSame('Cinema', $booking->pricing_profile_name);

        Carbon::setTestNow(Carbon::parse('2026-09-01 11:00:00'));
        $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/close")->assertOk();

        $this->assertEquals(500.0, (float) $booking->fresh()->total_price); // 1 hour @ 500/hr, not the room's 100
    }

    public function test_closing_honors_the_billing_buffer(): void
    {
        $owner = $this->owner();
        // Rate 10/hr, buffer 15 min — the documented example: 1h16m -> 2 blocks (20).
        $room = $this->room($owner, ['price_per_hour' => 10, 'billing_unit' => 'hour', 'billing_buffer_minutes' => 15]);
        $member = $this->member($owner);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $booking = $this->openSession($owner, $room, $member);

        Carbon::setTestNow(Carbon::parse('2026-09-01 11:16:00')); // 1h16m elapsed
        $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/close")->assertOk();

        $this->assertEquals(20.0, (float) $booking->fresh()->total_price);
    }

    public function test_closing_draws_package_minutes_and_marks_payment_method_package(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/packages", [
            'name' => '30 Hours', 'hours' => 30, 'price_paid' => 1500,
            'starts_on' => today()->toDateString(), 'expires_on' => today()->addDays(29)->toDateString(),
        ])->assertRedirect();
        $pkg = MemberPackage::where('owner_id', $owner->id)->where('hotspot_user_id', $member->id)->firstOrFail();

        $this->actingAs($owner, 'owner')->post('/bookings', [
            'duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'member_package_id' => $pkg->id,
        ])->assertRedirect();
        $booking = Booking::where('room_id', $room->id)->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-09-01 11:00:00'));
        $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/close")->assertOk();

        $booking->refresh();
        $this->assertSame(Booking::METHOD_PACKAGE, $booking->payment_method);
        $this->assertSame(60, $pkg->fresh()->used_minutes);
        $this->assertSame(1, PackageUsage::where('booking_id', $booking->id)->where('action', PackageUsage::BOOKING_USAGE)->count());
    }

    public function test_closing_applies_a_coupon_and_updates_net_room_charge(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        Coupon::create([
            'owner_id' => $owner->id, 'code' => 'SAVE20', 'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 20, 'applies_to' => Coupon::SCOPE_ROOMS, 'is_active' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $booking = $this->openSession($owner, $room, $member);

        Carbon::setTestNow(Carbon::parse('2026-09-01 11:00:00'));
        $this->actingAs($owner, 'owner')
            ->postJson("/bookings/{$booking->id}/close", ['coupon_code' => 'SAVE20'])
            ->assertOk();

        $booking->refresh();
        $this->assertEquals(100.0, (float) $booking->total_price);
        $this->assertEquals(20.0, (float) $booking->discount_total);
        $this->assertEquals(80.0, $booking->netRoomCharge());
        $this->assertSame(1, CouponUsage::where('booking_id', $booking->id)->count());
    }

    public function test_closing_frees_the_room_for_a_new_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->openSession($owner, $room, $member);

        $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/close")->assertOk();

        $this->actingAs($owner, 'owner')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => today()->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertRedirect();

        $this->assertSame(2, Booking::where('room_id', $room->id)->count());
    }

    public function test_double_close_is_rejected_and_does_not_double_charge(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->openSession($owner, $room, $member);

        $first = $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/close");
        $second = $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/close");

        $first->assertOk()->assertJson(['success' => true]);
        $second->assertStatus(409)->assertJson(['success' => false, 'message' => __('app.booking.duration_type.already_closed')]);
        $this->assertSame(1, Booking::where('id', $booking->id)->count());
        $this->assertSame('completed', $booking->fresh()->status);
    }

    // ------------------------------------------------------------------ invoice items

    public function test_invoice_items_can_be_added_while_open_and_are_frozen_after_checkout(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $product = Product::create(['owner_id' => $owner->id, 'name' => 'Coffee', 'type' => 'product', 'price' => 20, 'is_active' => true, 'track_stock' => false]);
        $booking = $this->openSession($owner, $room, $member);

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect();
        $this->assertSame(1, SaleItem::count());

        $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/close")->assertOk();

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHas('error', __('app.sales.invoice_not_editable'));
        $this->assertSame(1, SaleItem::count());

        $booking->refresh();
        $this->assertEquals(40.0, (float) $booking->sale->total);
        $this->assertEquals($booking->total_price + 40.0, $booking->grandTotal());
    }

    // ------------------------------------------------------------------ tenancy & permissions

    public function test_owner_cannot_preview_or_close_another_owners_open_session(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->openSession($owner, $room, $member);

        $this->actingAs($intruder, 'owner')->getJson("/bookings/{$booking->id}/close-preview")->assertNotFound();
        // close() claims by an atomic `UPDATE ... WHERE owner_id = ? AND status = 'open'` (the
        // same double-checkout-safe pattern as SharedSessionController::close()) — a foreign
        // owner_id simply claims 0 rows, which reads identically to "someone else already closed
        // it" (409), not a 404. The lookup-based closePreview() above still 404s normally.
        $this->actingAs($intruder, 'owner')->postJson("/bookings/{$booking->id}/close")->assertStatus(409);

        $this->assertSame('open', $booking->fresh()->status);
    }

    public function test_closing_requires_bookings_edit_permission(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->openSession($owner, $room, $member);

        $staff = $this->staff($owner, 'receptionist');
        $staff->permissions()->detach(Permission::where('key', 'bookings.edit')->value('id'));

        auth('owner')->logout();
        $this->actingAs($staff, 'staff')->postJson("/bookings/{$booking->id}/close");

        $this->assertSame('open', $booking->fresh()->status);
    }

    // ------------------------------------------------------------------ UI

    public function test_create_form_shows_the_duration_type_toggle(): void
    {
        $owner = $this->owner();
        $this->room($owner);

        $this->actingAs($owner, 'owner')->get('/bookings/create')
            ->assertOk()
            ->assertSee(__('app.booking.duration_type.label'))
            ->assertSee(__('app.booking.duration_type.open'));
    }

    public function test_close_preview_returns_a_live_quote_without_mutating_the_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, ['price_per_hour' => 100]);
        $member = $this->member($owner);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $booking = $this->openSession($owner, $room, $member);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:30:00'));
        $this->actingAs($owner, 'owner')->getJson("/bookings/{$booking->id}/close-preview")
            ->assertOk()
            ->assertJson([
                'session_id' => $booking->id,
                'room_name' => $room->name,
                'total_price_raw' => 50.0, // 30 minutes @ 100/hr
            ]);

        $booking->refresh();
        $this->assertSame('open', $booking->status);
        $this->assertEquals(0.0, (float) $booking->total_price);
    }

    public function test_booking_show_page_renders_for_an_open_session_with_a_checkout_action(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->openSession($owner, $room, $member);

        $this->actingAs($owner, 'owner')->get("/bookings/{$booking->id}")
            ->assertOk()
            ->assertSee(__('app.booking.duration_type.badge'))
            ->assertSee(__('app.booking.duration_type.checkout'));
    }
}
