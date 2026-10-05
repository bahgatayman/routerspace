<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Workspace;
use App\Services\AvailabilityService;
use App\Services\OccupancyAnalyticsService;
use App\Support\ActiveSessionsQuery;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OccupancyAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private OccupancyAnalyticsService $occupancy;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->occupancy = app(OccupancyAnalyticsService::class);
        $this->availability = app(AvailabilityService::class);
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

    private function room(Owner $owner, string $type, int $capacity, bool $isAvailable = true, string $name = 'Room'): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name,
            'type' => $type, 'capacity' => $capacity, 'price_per_hour' => 50,
            'is_available' => $isAvailable,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
    }

    private function booking(
        Owner $owner,
        Room $room,
        string $date,
        string $start,
        string $end,
        int $partySize = 1,
        string $status = 'confirmed',
    ): Booking {
        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id,
            'hotspot_user_id' => $this->member($owner)->id,
            'party_size' => $partySize,
            'booking_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'price_per_hour' => 50, 'total_hours' => 2, 'total_price' => 100,
            'status' => $status,
        ]);
    }

    private function openSession(Owner $owner, Room $room, int $partySize = 1): SharedSession
    {
        return SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $room->id,
            'hotspot_user_id' => $this->member($owner)->id,
            'party_size' => $partySize,
            'session_date' => today()->toDateString(), 'start_time' => now()->format('H:i'),
            'opened_at' => now(), 'status' => 'open',
        ]);
    }

    // --- Basic occupancy ---

    public function test_a_currently_overlapping_confirmed_booking_occupies_an_exclusive_room(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 1);
        $this->booking($owner, $room, '2026-08-27', '09:00', '11:00');

        $result = $this->occupancy->currentOccupancy($owner);

        $this->assertSame(1, $result['capacity']);
        $this->assertSame(1, $result['occupied']);
        $this->assertSame(0, $result['available']);
        $this->assertSame(100.0, $result['percent']);
    }

    public function test_a_booking_outside_its_own_time_window_does_not_occupy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 08:00:00')); // before 09:00 start
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 1);
        $this->booking($owner, $room, '2026-08-27', '09:00', '11:00');

        $result = $this->occupancy->currentOccupancy($owner);

        $this->assertSame(0, $result['occupied']);
        $this->assertSame(1, $result['available']);
    }

    public function test_open_shared_session_party_size_counts_toward_occupancy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner, 'shared', 10);
        $this->openSession($owner, $room, partySize: 3);

        $result = $this->occupancy->currentOccupancy($owner);

        $this->assertSame(10, $result['capacity']);
        $this->assertSame(3, $result['occupied']);
        $this->assertSame(7, $result['available']);
    }

    public function test_past_no_show_grace_confirmed_booking_in_a_shared_room_is_excluded(): void
    {
        // Booking starts 09:00; grace is 30 minutes; "now" is 10:00, well past grace.
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $room = $this->room($owner, 'shared', 10);
        $this->booking($owner, $room, '2026-08-27', '09:00', '11:00', partySize: 4, status: 'confirmed');

        $result = $this->occupancy->currentOccupancy($owner);

        $this->assertSame(0, $result['occupied']);
    }

    public function test_owner_scoping_excludes_other_owners_rooms_and_bookings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $other = $this->owner();
        $room = $this->room($owner, 'meeting', 1);
        $otherRoom = $this->room($other, 'meeting', 1);

        $this->booking($owner, $room, '2026-08-27', '09:00', '11:00');
        $this->booking($other, $otherRoom, '2026-08-27', '09:00', '11:00');

        $result = $this->occupancy->currentOccupancy($owner);

        $this->assertCount(1, $result['rooms']);
        $this->assertSame($room->id, $result['rooms'][0]['room_id']);
    }

    // --- Parity with AvailabilityService::usedCapacityNow() ---

    /**
     * OccupancyAnalyticsService deliberately reimplements
     * usedCapacityNow()'s rules in a batched form rather than calling it
     * per room. This proves the batched numbers match the per-room service
     * exactly across a mixed fixture (exclusive + shared rooms, a
     * past-grace confirmed booking, an open session, a pending booking that
     * doesn't overlap now).
     */
    public function test_batched_occupancy_matches_availability_service_per_room(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();

        $exclusive = $this->room($owner, 'meeting', 1, name: 'Exclusive');
        $this->booking($owner, $exclusive, '2026-08-27', '09:00', '11:00', status: 'confirmed');

        $shared = $this->room($owner, 'shared', 10, name: 'Shared');
        $this->booking($owner, $shared, '2026-08-27', '09:00', '11:00', partySize: 4, status: 'confirmed'); // past grace, excluded
        $this->booking($owner, $shared, '2026-08-27', '09:55', '11:00', partySize: 2, status: 'pending'); // within grace, counted
        $this->openSession($owner, $shared, partySize: 1);

        $untouched = $this->room($owner, 'office', 1, name: 'Untouched');
        $this->booking($owner, $untouched, '2026-08-27', '13:00', '14:00'); // future, not overlapping now

        $result = $this->occupancy->currentOccupancy($owner);
        $byRoom = collect($result['rooms'])->keyBy('room_id');

        foreach ([$exclusive, $shared, $untouched] as $room) {
            $this->assertSame(
                $this->availability->usedCapacityNow($room),
                $byRoom[$room->id]['occupied'],
                "Mismatch for room {$room->name}",
            );
        }
    }

    /**
     * The deliberate divergence between occupancy counting and Active
     * Sessions visibility, now backed by one named primitive
     * (AvailabilityService::bookingsOverlappingNow()/rejectPastGrace())
     * instead of three independently hand-written queries that could drift:
     * a confirmed shared-room booking past its no-show grace stops counting
     * toward occupancy (business rule: probably a no-show), but Active
     * Sessions keeps showing it until its own end_time (business rule: still
     * visible/actionable for check-in, not the owner's internal capacity math).
     */
    public function test_occupancy_excludes_past_grace_booking_that_active_sessions_still_shows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();
        $shared = $this->room($owner, 'shared', 10);
        // Starts 09:00 (grace expires 09:30, well before "now" 10:00) but
        // doesn't end until 11:00 — still inside Active Sessions' own
        // end_time-gated visibility window.
        $this->booking($owner, $shared, '2026-08-27', '09:00', '11:00', partySize: 4, status: 'confirmed');

        $this->assertSame(0, $this->occupancy->currentOccupancy($owner)['occupied']);
        $this->assertCount(1, ActiveSessionsQuery::build($owner->id));
    }

    // --- availableRoomsNow ---

    public function test_available_rooms_now_excludes_disabled_and_fully_occupied_rooms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 10:00:00'));
        $owner = $this->owner();

        $free = $this->room($owner, 'meeting', 1, isAvailable: true, name: 'Free');
        $disabled = $this->room($owner, 'meeting', 1, isAvailable: false, name: 'Disabled');
        $full = $this->room($owner, 'meeting', 1, isAvailable: true, name: 'Full');
        $this->booking($owner, $full, '2026-08-27', '09:00', '11:00');

        $this->assertSame(1, $this->occupancy->availableRoomsNow($owner));
    }
}
