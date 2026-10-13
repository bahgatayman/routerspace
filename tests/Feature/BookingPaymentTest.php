<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BookingController::store()/update() persisting the new amount_paid/
 * payment_status fields — the deposit-collection half of the booking flow
 * redesign. Nothing about the existing validation/capacity/lock behavior
 * changes here; this only covers the new payment layer on top of it.
 */
class BookingPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking'],
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

    private function room(Owner $owner, string $type = 'meeting', float $pricePerHour = 100): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room',
            'type' => $type, 'capacity' => 4, 'price_per_hour' => $pricePerHour,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
    }

    private function bookingPayload(Room $room, HotspotUser $user, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'booking_date' => today()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00',
        ], $overrides);
    }

    // --- store() ---

    public function test_store_persists_no_deposit_as_unpaid(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user));

        $booking = Booking::sole();
        $this->assertSame(0.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_UNPAID, $booking->payment_status);
    }

    public function test_store_persists_a_partial_deposit(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100); // 2 hours => 200 total
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, ['amount_paid' => 100]));

        $booking = Booking::sole();
        $this->assertSame(200.0, (float) $booking->total_price);
        $this->assertSame(100.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PARTIAL, $booking->payment_status);
    }

    public function test_store_persists_a_full_payment(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, ['amount_paid' => 200]));

        $booking = Booking::sole();
        $this->assertSame(200.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PAID, $booking->payment_status);
    }

    public function test_store_rejects_a_deposit_above_the_total(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        $response = $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, ['amount_paid' => 500]));

        $response->assertSessionHas('error');
        $this->assertSame(0, Booking::count());
    }

    public function test_store_rejects_a_negative_deposit(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);

        $response = $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, ['amount_paid' => -50]));

        $response->assertSessionHasErrors('amount_paid');
        $this->assertSame(0, Booking::count());
    }

    public function test_store_ignores_a_submitted_deposit_for_a_shared_room(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, type: 'shared', pricePerHour: 40);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, ['amount_paid' => 80]));

        $booking = Booking::sole();
        $this->assertSame(0.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_UNPAID, $booking->payment_status);
    }

    // --- update() ---

    public function test_update_keeps_the_existing_deposit_when_not_resubmitted(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, ['amount_paid' => 100]));
        $booking = Booking::sole();

        $this->actingAs($owner, 'owner')->put("/bookings/{$booking->id}", $this->bookingPayload($room, $user, [
            'end_time' => '13:00', // 3 hours now, still 100 deposit implied
        ]));

        $booking->refresh();
        $this->assertSame(300.0, (float) $booking->total_price);
        $this->assertSame(100.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PARTIAL, $booking->payment_status);
    }

    public function test_update_rejects_a_deposit_that_now_exceeds_a_shortened_total(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        // 3 hours @ 100 = 300, fully paid.
        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, [
            'end_time' => '13:00', 'amount_paid' => 300,
        ]));
        $booking = Booking::sole();

        // Shortened to 1 hour = 100 total, but the 300 already paid is now
        // never explicitly resubmitted — carried over, and must be rejected
        // rather than silently exceeding the new total.
        $response = $this->actingAs($owner, 'owner')->put("/bookings/{$booking->id}", $this->bookingPayload($room, $user, [
            'end_time' => '11:00',
        ]));

        $response->assertSessionHas('error');
        $booking->refresh();
        $this->assertSame(300.0, (float) $booking->total_price, 'the booking should not have been shortened');
    }

    public function test_update_re_derives_payment_status_when_total_grows(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        // 1 hour @ 100 = 100, fully paid.
        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, [
            'end_time' => '11:00', 'amount_paid' => 100,
        ]));
        $booking = Booking::sole();
        $this->assertSame(100.0, (float) $booking->total_price);
        $this->assertSame(Booking::PAYMENT_PAID, $booking->payment_status);

        // Extended to 2 hours = 200 total; the same 100 already paid is now only partial.
        $this->actingAs($owner, 'owner')->put("/bookings/{$booking->id}", $this->bookingPayload($room, $user, [
            'end_time' => '12:00',
        ]));

        $booking->refresh();
        $this->assertSame(200.0, (float) $booking->total_price);
        $this->assertSame(100.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PARTIAL, $booking->payment_status);
    }

    // --- explicit payment choice (No / Partial / Full) ---

    public function test_full_payment_choice_records_the_servers_own_total_not_a_stale_client_amount(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100); // 2 hours => 200
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, [
            'payment_choice' => 'full', 'amount_paid' => 150, // stale figure from an older total
        ]));

        $booking = Booking::sole();
        $this->assertSame(200.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PAID, $booking->payment_status);
    }

    public function test_no_payment_choice_records_zero_even_if_an_amount_was_left_in_the_field(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, [
            'payment_choice' => 'none', 'amount_paid' => 80,
        ]));

        $booking = Booking::sole();
        $this->assertSame(0.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_UNPAID, $booking->payment_status);
    }

    public function test_partial_payment_choice_records_the_typed_amount(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, [
            'payment_choice' => 'partial', 'amount_paid' => 75.5,
        ]));

        $booking = Booking::sole();
        $this->assertSame(75.5, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PARTIAL, $booking->payment_status);
    }

    public function test_partial_payment_choice_rejects_zero_and_amounts_above_the_total_without_creating_a_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, [
            'payment_choice' => 'partial', 'amount_paid' => 0,
        ]))->assertSessionHasErrors();

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, [
            'payment_choice' => 'partial', 'amount_paid' => 250,
        ]))->assertSessionHas('error');

        $this->assertSame(0, Booking::count());
    }

    public function test_full_payment_choice_on_update_follows_a_changed_total(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, pricePerHour: 100);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->bookingPayload($room, $user, ['payment_choice' => 'full']));
        $booking = Booking::sole();

        // Extended to 3 hours while "Full payment" stays selected: the full new total is recorded.
        $this->actingAs($owner, 'owner')->put("/bookings/{$booking->id}", $this->bookingPayload($room, $user, [
            'end_time' => '13:00', 'payment_choice' => 'full', 'amount_paid' => 200,
        ]));

        $booking->refresh();
        $this->assertSame(300.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PAID, $booking->payment_status);
    }

    public function test_booking_form_shows_payment_choices_and_notes_without_expanding(): void
    {
        $owner = $this->owner();
        $this->room($owner);

        $html = $this->actingAs($owner, 'owner')->get('/bookings/create')->assertOk()->getContent();

        foreach (['none', 'partial', 'full'] as $choice) {
            $this->assertStringContainsString('name="payment_choice" value="'.$choice.'"', $html);
        }
        $this->assertDoesNotMatchRegularExpression('/name="payment_choice" value="[a-z]+"[^>]*checked/', $html, 'no payment option is pre-selected — the choice is required');
        $this->assertStringContainsString(json_encode(__('app.booking.payment.choice_required')), $html);
        $this->assertStringContainsString('id="f-notes"', $html);
        $this->assertStringNotContainsString('ls-notes-details', $html);
    }
}
