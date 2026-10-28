<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\IdempotencyKey;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Booking creation is once-only per request key (RequestIdempotency): an
 * identical retry gets the original booking back, a different request under
 * the same key is refused, a rejected attempt never consumes its key, and
 * requests without a key behave exactly as before.
 */
class BookingIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '7f9c2b4e-1a3d-4c5b-9e8f-0a1b2c3d4e5f';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function owner(): Owner
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $type = 'meeting', int $capacity = 4): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room '.uniqid(), 'type' => $type, 'capacity' => $capacity, 'price_per_hour' => 100]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    private function payload(Room $room, HotspotUser $user, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'booking_date' => today()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00',
            'idempotency_key' => self::KEY,
        ], $overrides);
    }

    public function test_a_repeated_form_submit_creates_one_booking_and_returns_it(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);

        $first = $this->actingAs($owner, 'owner')->post('/bookings', $this->payload($room, $user));
        $booking = Booking::sole();
        $first->assertRedirect("/bookings/{$booking->id}");

        $this->actingAs($owner, 'owner')->post('/bookings', $this->payload($room, $user))
            ->assertRedirect("/bookings/{$booking->id}")
            ->assertSessionHas('success', __('app.booking.already_created'));

        $this->assertSame(1, Booking::count());
        $this->assertSame($booking->id, IdempotencyKey::sole()->booking_id);
    }

    public function test_a_repeated_json_request_replays_the_same_booking(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);

        $id = $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))->assertOk()->json('booking_id');

        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJson(['success' => true, 'booking_id' => $id, 'url' => "/bookings/{$id}"]);

        $this->assertSame(1, Booking::count());
    }

    public function test_the_header_key_works_too_and_string_vs_number_input_is_the_same_request(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);
        $payload = $this->payload($room, $user, ['idempotency_key' => null, 'guest_count' => '3']);

        $this->actingAs($owner, 'owner')->postJson('/bookings', $payload, ['Idempotency-Key' => self::KEY])->assertOk();
        $this->actingAs($owner, 'owner')->postJson('/bookings', ['guest_count' => 3] + $payload, ['X-Idempotency-Key' => self::KEY])
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, Booking::count());
    }

    public function test_the_same_key_with_a_different_request_is_refused(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))->assertOk();

        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user, ['start_time' => '14:00', 'end_time' => '15:00']))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => __('app.booking.idempotency_mismatch')]);
        $this->actingAs($owner, 'owner')->post('/bookings', $this->payload($room, $user, ['notes' => 'changed']))
            ->assertSessionHas('error', __('app.booking.idempotency_mismatch'));

        $this->assertSame(1, Booking::count());
    }

    public function test_a_rejected_attempt_does_not_consume_its_key(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);
        // The slot is taken: the keyed attempt is rejected for capacity.
        $blocker = Booking::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id, 'party_size' => 1,
            'booking_date' => today()->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '13:00',
            'price_per_hour' => 100, 'total_hours' => 4, 'total_price' => 400, 'status' => 'confirmed']);

        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))->assertStatus(422);
        $this->assertSame(0, IdempotencyKey::count(), 'A rejected attempt leaves no claim behind.');

        // A validation failure doesn't consume it either.
        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user, ['end_time' => '09:00']))->assertStatus(422);
        $this->assertSame(0, IdempotencyKey::count());

        // Once the slot frees up, the very same request goes through — once.
        $blocker->update(['status' => 'cancelled']);
        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(2, Booking::count()); // the cancelled blocker + exactly one new booking
    }

    public function test_a_shared_room_retry_takes_its_seats_once(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared', 4);
        $user = $this->member($owner);
        $payload = $this->payload($room, $user, ['party_size' => 2]);

        $this->actingAs($owner, 'owner')->postJson('/bookings', $payload)->assertOk();
        $this->actingAs($owner, 'owner')->postJson('/bookings', $payload)->assertOk();

        $this->assertSame(1, Booking::count());
        $this->assertSame(2, (int) Booking::sum('party_size'));
    }

    public function test_without_a_key_behaviour_is_unchanged(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared', 4);
        $user = $this->member($owner);
        $payload = $this->payload($room, $user, ['party_size' => 2, 'idempotency_key' => null]);

        $this->actingAs($owner, 'owner')->postJson('/bookings', $payload)->assertOk();
        $this->actingAs($owner, 'owner')->postJson('/bookings', $payload)->assertOk();

        $this->assertSame(2, Booking::count(), 'No key, no guard — exactly as before.');
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_an_open_session_retry_creates_one_session(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $payload = ['duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id, 'idempotency_key' => self::KEY];

        $id = $this->actingAs($owner, 'owner')->postJson('/bookings', $payload)->assertOk()->json('booking_id');
        $this->actingAs($owner, 'owner')->postJson('/bookings', $payload)->assertOk()->assertJson(['booking_id' => $id]);

        $this->assertSame(1, Booking::where('status', 'open')->count());
    }

    public function test_keys_are_scoped_per_owner(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $roomA = $this->room($a);
        $roomB = $this->room($b);

        $this->actingAs($a, 'owner')->postJson('/bookings', $this->payload($roomA, $this->member($a)))->assertOk();
        $this->actingAs($b, 'owner')->postJson('/bookings', $this->payload($roomB, $this->member($b)))->assertOk()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(1, Booking::where('owner_id', $a->id)->count());
        $this->assertSame(1, Booking::where('owner_id', $b->id)->count());
    }

    public function test_a_malformed_key_is_rejected_before_anything_is_written(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user, ['idempotency_key' => 'bad key!']))
            ->assertStatus(422)->assertJson(['message' => __('app.booking.idempotency_invalid')]);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_retry_after_the_booking_was_deleted_is_refused_not_recreated(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))->assertOk();
        Booking::sole()->delete();

        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))
            ->assertStatus(409)->assertJson(['message' => __('app.booking.idempotency_gone')]);
        $this->assertSame(0, Booking::count());
    }

    public function test_keys_expire_after_24_hours_and_are_pruned(): void
    {
        // A fixed instant months away from either daylight-saving change in
        // Africa/Cairo: timestamps are stored as local wall-clock time, so a
        // relative jump across the autumn fall-back (25 real hours, 24 on the
        // clock) used to make this test depend on the day it ran.
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);
        $first = $this->payload($room, $user); // the same request, sent again below

        $this->actingAs($owner, 'owner')->postJson('/bookings', $first)->assertOk();
        $key = IdempotencyKey::sole();
        $this->assertSame('2026-06-16 12:00:00', $key->expires_at->toDateTimeString(), 'Valid for exactly 24 hours.');

        // One minute before expiry: still guarding — a retry is answered with the original booking, and pruning keeps it.
        $this->travelTo($key->expires_at->copy()->subMinute());
        $this->actingAs($owner, 'owner')->postJson('/bookings', $first)
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJson(['booking_id' => Booking::sole()->id]);
        Artisan::call('model:prune', ['--model' => [IdempotencyKey::class]]);
        $this->assertSame(1, IdempotencyKey::count(), 'Not pruned before it expires.');
        $this->assertSame(1, Booking::count());

        // One minute after expiry: the key no longer guards anything and can be used afresh.
        $this->travelTo($key->expires_at->copy()->addMinute());
        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user, [
            'booking_date' => '2026-06-18', 'start_time' => '15:00', 'end_time' => '16:00',
        ]))->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, Booking::count());
        $second = IdempotencyKey::sole(); // the expired claim made way for the new one
        $this->assertSame('2026-06-17 12:01:00', $second->expires_at->toDateTimeString());

        // Pruning: kept up to its expiry, gone right after.
        $this->travelTo($second->expires_at->copy()->subMinute());
        Artisan::call('model:prune', ['--model' => [IdempotencyKey::class]]);
        $this->assertSame(1, IdempotencyKey::count());
        $this->travelTo($second->expires_at->copy()->addMinute());
        Artisan::call('model:prune', ['--model' => [IdempotencyKey::class]]);
        $this->assertSame(0, IdempotencyKey::count());
    }
}
