<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\IdempotencyKey;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use App\Support\RequestIdempotency;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Linking a booking to its once-only key must never move the key's expiry.
 * On MySQL/MariaDB with explicit_defaults_for_timestamp = OFF the server gave
 * idempotency_keys.expires_at ON UPDATE CURRENT_TIMESTAMP, so that UPDATE
 * expired the key at once and a later retry booked twice. Runs on every
 * engine; it reproduces the defect against a server started with
 * --explicit-defaults-for-timestamp=0 (without the 2026_10_30_000003 fix).
 */
class IdempotencyKeyExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '3c1d9e2a-5b7f-4a60-8d1e-2f3a4b5c6d7e';

    private Owner $owner;

    private Room $room;

    private HotspotUser $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $this->owner = Owner::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        foreach ($plan->features as $key) {
            $this->owner->enableFeature($key);
        }
        $ws = Workspace::create(['owner_id' => $this->owner->id, 'name' => 'Main']);
        $this->room = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $ws->id, 'name' => 'Room', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100]);
        $this->member = HotspotUser::create(['owner_id' => $this->owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    public function test_linking_a_booking_to_its_key_keeps_the_expiry(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $extra = DB::selectOne("SELECT EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'idempotency_keys' AND COLUMN_NAME = 'expires_at'")->extra;
            $this->assertStringNotContainsStringIgnoringCase('on update', (string) $extra, 'expires_at must not follow row updates.');
        }

        $this->actingAs($this->owner, 'owner');
        $claim = DB::transaction(fn () => RequestIdempotency::claim($this->owner->id, RequestIdempotency::SCOPE_BOOKING_CREATE, self::KEY, str_repeat('a', 64)));
        $expires = $claim->fresh()->expires_at->toDateTimeString();
        $this->assertTrue($claim->fresh()->expires_at->isFuture());

        $booking = Booking::create(['owner_id' => $this->owner->id, 'room_id' => $this->room->id, 'hotspot_user_id' => $this->member->id, 'party_size' => 1,
            'booking_date' => today()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00', 'price_per_hour' => 100,
            'total_hours' => 2, 'total_price' => 200, 'amount_paid' => 0, 'payment_status' => 'unpaid', 'status' => 'confirmed']);
        // The same write BookingController::store() makes once the booking exists.
        $claim->update(['booking_id' => $booking->id]);

        $this->assertSame($expires, $claim->fresh()->expires_at->toDateTimeString());
        $this->travel(10)->minutes();
        $this->assertSame($booking->id, RequestIdempotency::find($this->owner->id, RequestIdempotency::SCOPE_BOOKING_CREATE, self::KEY)?->booking_id);
    }

    public function test_a_retry_minutes_later_still_returns_the_original_booking(): void
    {
        $payload = ['room_id' => $this->room->id, 'hotspot_user_id' => $this->member->id, 'booking_date' => today()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00', 'idempotency_key' => self::KEY];

        $id = $this->actingAs($this->owner, 'owner')->postJson('/bookings', $payload)->assertOk()->json('booking_id');
        $expires = IdempotencyKey::sole()->expires_at->toDateTimeString();

        // A slow network retry: well inside the 24-hour window.
        $this->travel(10)->minutes();
        $this->actingAs($this->owner, 'owner')->postJson('/bookings', $payload)
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJson(['booking_id' => $id]);

        $this->assertSame(1, Booking::count(), 'No duplicate booking.');
        $this->assertSame($expires, IdempotencyKey::sole()->expires_at->toDateTimeString());
    }

    public function test_a_shared_room_retry_minutes_later_takes_its_seats_once(): void
    {
        // Free seats left: only the key stops a second copy of the booking.
        $hall = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->room->workspace_id, 'name' => 'Hall', 'type' => 'shared', 'capacity' => 6, 'price_per_hour' => 60]);
        $payload = ['room_id' => $hall->id, 'hotspot_user_id' => $this->member->id, 'booking_date' => today()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00', 'party_size' => 2, 'idempotency_key' => self::KEY];

        $id = $this->actingAs($this->owner, 'owner')->postJson('/bookings', $payload)->assertOk()->json('booking_id');
        $this->travel(10)->minutes();
        $this->actingAs($this->owner, 'owner')->postJson('/bookings', $payload)->assertOk()->assertJson(['booking_id' => $id]);

        $this->assertSame(1, Booking::where('room_id', $hall->id)->count(), 'No duplicate booking.');
    }

    public function test_an_open_session_retry_minutes_later_still_returns_the_original_booking(): void
    {
        $payload = ['duration_type' => 'open', 'room_id' => $this->room->id, 'hotspot_user_id' => $this->member->id, 'idempotency_key' => self::KEY];

        $id = $this->actingAs($this->owner, 'owner')->postJson('/bookings', $payload)->assertOk()->json('booking_id');
        $this->travel(10)->minutes();
        $this->actingAs($this->owner, 'owner')->postJson('/bookings', $payload)->assertOk()->assertJson(['booking_id' => $id]);

        $this->assertSame(1, Booking::where('status', 'open')->count(), 'No second open session.');
    }
}
