<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Working Hours Phase 4: AvailabilityService::freeBusyForDay()/bookableSlots()
 * becoming business-hours-aware, replacing the old fixed
 * OPERATING_START/OPERATING_END bound whenever an owner has configured
 * hours. An owner who hasn't configured hours yet is covered by the
 * (unmodified, still-passing) AvailabilityServiceTest — this file is only
 * about the new behavior once hours exist.
 */
class AvailabilityWorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->availability = app(AvailabilityService::class);
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

    private function room(Owner $owner, string $type = 'meeting', int $capacity = 4): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room',
            'type' => $type, 'capacity' => $capacity, 'price_per_hour' => 40,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
    }

    private function day(Owner $owner, int $dayOfWeek, array $overrides = []): WorkingHour
    {
        return WorkingHour::create(array_merge([
            'owner_id' => $owner->id,
            'day_of_week' => $dayOfWeek,
            'is_open' => true,
            'open_time' => '10:00:00',
            'close_time' => '22:00:00',
        ], $overrides));
    }

    // --- freeBusyForDay(): closed segments ---

    public function test_a_clean_single_window_day_has_no_closed_padding(): void
    {
        // No bookings, one open segment, nothing outside it — the displayed
        // window should start/end exactly at the open hours rather than
        // showing hours of dead closed space before/after (that used to
        // force excessive scrolling on the calendar for no benefit).
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']); // Tuesday.

        $segments = $this->availability->freeBusyForDay($room, '2026-08-18'); // Tuesday.

        $this->assertCount(1, $segments);
        $this->assertSame('10:00', $segments[0]['start']);
        $this->assertSame('22:00', $segments[0]['end']);
        $this->assertFalse($segments[0]['closed']);
    }

    public function test_a_gap_between_two_open_segments_is_marked_closed(): void
    {
        // Thursday 22:00->02:00 spills into Friday, and Friday also has its
        // own separate 10:00-22:00 window — the closed 02:00-10:00 gap
        // falls INSIDE the displayed range either way (it's between two
        // open segments, not padding around them), so it must still show.
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']);
        $this->day($owner, 5, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);

        $segments = $this->availability->freeBusyForDay($room, '2026-08-21'); // Friday.

        $gap = collect($segments)->first(fn ($s) => $s['start'] === '02:00');
        $this->assertNotNull($gap);
        $this->assertTrue($gap['closed']);
        $this->assertSame('10:00', $gap['end']);
    }

    public function test_fully_closed_day_with_nothing_booked_falls_back_to_the_operating_window(): void
    {
        // Nothing open and nothing booked that day — there's no real data to
        // anchor a display window to, so this falls back to the legacy
        // OPERATING_START/OPERATING_END range rather than showing nothing.
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->day($owner, 2, ['is_open' => false, 'open_time' => null, 'close_time' => null]);

        $segments = $this->availability->freeBusyForDay($room, '2026-08-18');

        $this->assertCount(1, $segments);
        $this->assertTrue($segments[0]['closed']);
        $this->assertSame(AvailabilityService::OPERATING_START, $segments[0]['start']);
        $this->assertSame(AvailabilityService::OPERATING_END, $segments[0]['end']);
    }

    public function test_a_legacy_booking_outside_newly_configured_hours_stays_visible_but_unavailable(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $user = $this->member($owner);

        // Booking created before hours existed, now outside the configured window.
        Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => '2026-08-18', 'start_time' => '07:00', 'end_time' => '08:00',
            'price_per_hour' => 40, 'total_hours' => 1, 'total_price' => 40, 'status' => 'confirmed',
        ]);
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);

        $segments = $this->availability->freeBusyForDay($room, '2026-08-18');
        $legacySegment = collect($segments)->first(fn ($s) => $s['start'] === '07:00');

        $this->assertNotNull($legacySegment, 'The legacy booking must still be represented, not silently dropped.');
        $this->assertSame(1, $legacySegment['used']);
        $this->assertTrue($legacySegment['closed']);
        $this->assertSame(0, $legacySegment['available']);
    }

    public function test_unconfigured_owner_keeps_the_old_fixed_operating_window(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        $segments = $this->availability->freeBusyForDay($room, '2026-08-18');

        $this->assertSame(AvailabilityService::OPERATING_START, $segments[0]['start']);
        $this->assertSame(AvailabilityService::OPERATING_END, end($segments)['end']);
        foreach ($segments as $segment) {
            $this->assertFalse($segment['closed']);
        }
    }

    // --- bookableSlots(): only inside open segments ---

    public function test_fully_closed_day_offers_no_bookable_slots(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->day($owner, 2, ['is_open' => false, 'open_time' => null, 'close_time' => null]);

        $this->assertSame([], $this->availability->bookableSlots($room, '2026-08-18'));
    }

    public function test_slot_outside_the_single_configured_window_is_not_generated_at_all(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);

        $slots = collect($this->availability->bookableSlots($room, '2026-08-18'));

        // A single open segment means the envelope IS that segment — nothing
        // outside it is generated at all, closed or otherwise.
        $this->assertNull($slots->firstWhere('start', '08:00'));
        $this->assertTrue($slots->firstWhere('start', '11:00')['available']);
    }

    public function test_slot_in_the_gap_between_two_open_segments_is_marked_unavailable(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        // Thursday 22:00->02:00 spills into Friday, and Friday also has its
        // own separate 10:00-22:00 window — two disjoint open segments on
        // Friday with a closed 02:00-10:00 gap between them. The envelope
        // spans both, so the gap itself still generates (unavailable) slots.
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']);
        $this->day($owner, 5, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);

        $slots = collect($this->availability->bookableSlots($room, '2026-08-21')); // Friday.

        $gapSlot = $slots->firstWhere('start', '05:00');
        $this->assertNotNull($gapSlot, 'A slot must still be generated inside the envelope, even in the closed gap.');
        $this->assertFalse($gapSlot['available']);
        $this->assertTrue($slots->firstWhere('start', '11:00')['available']);
    }

    public function test_bookable_slots_respect_an_overnight_window(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        // Thursday 22:00 -> 02:00 overnight; Friday's own day has no row.
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']);

        $slots = collect($this->availability->bookableSlots($room, '2026-08-21')); // Friday.

        $this->assertTrue($slots->firstWhere('start', '00:00')['available']);
        // Friday's own day has no configured row and the spillover ends at
        // 02:00 — the envelope of open segments for this date stops there,
        // so no slot is even generated this far into the (closed) day.
        $this->assertNull($slots->firstWhere('start', '10:00'));
    }

    // --- checkAvailability() JSON endpoint agrees with the submit-time check ---

    public function test_check_availability_endpoint_reports_unavailable_outside_working_hours(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);

        $this->actingAs($owner, 'owner')
            ->getJson('/bookings/check-availability?'.http_build_query([
                'room_id' => $room->id, 'booking_date' => '2026-08-18',
                'start_time' => '07:00', 'end_time' => '08:00',
            ]))
            ->assertOk()
            ->assertJson(['available' => false]);
    }

    public function test_check_availability_endpoint_agrees_with_a_valid_slot(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);

        $this->actingAs($owner, 'owner')
            ->getJson('/bookings/check-availability?'.http_build_query([
                'room_id' => $room->id, 'booking_date' => '2026-08-18',
                'start_time' => '11:00', 'end_time' => '12:00',
            ]))
            ->assertOk()
            ->assertJson(['available' => true]);
    }

    // --- Calendar view renders the closed state ---

    public function test_day_view_shows_a_closed_tooltip_for_a_legacy_out_of_window_booking(): void
    {
        // A clean single-window day with nothing booked outside it no
        // longer renders any closed segment at all (that's the point of
        // trimming the display window) — the calendar-legend partial always
        // mentions the word "Closed" in its static key, on every day view,
        // so asserting on that page-wide text alone would pass regardless
        // of whether an actual closed segment exists. This test instead
        // forces a real closed segment (a booking outside the configured
        // hours) and asserts on its specific tooltip content.
        $owner = $this->owner();
        $room = $this->room($owner);
        $room->bookings()->create([
            'owner_id' => $owner->id, 'hotspot_user_id' => $this->member($owner)->id,
            'party_size' => 1, 'booking_date' => '2026-08-18', 'start_time' => '07:00', 'end_time' => '08:00',
            'price_per_hour' => 40, 'total_hours' => 1, 'total_price' => 40, 'status' => 'confirmed',
        ]);
        $this->day($owner, Carbon::parse('2026-08-18')->dayOfWeek, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date=2026-08-18');

        // The 07:00-08:00 (booked) and 08:00-10:00 (empty) closed spans
        // have different 'used' counts, so they render as two adjacent
        // segments rather than merging into one 07:00-10:00 block.
        // (The React DayRooms renders a closed block's tooltip as
        // "start–end: <working_hours.closed>", so the block data is asserted.)
        $response->assertOk();
        $blocks = collect($response->inertiaProps('dayRooms'))->firstWhere('room.id', $room->id)['blocks'];
        $closed = collect($blocks)->first(fn ($b) => $b['start'] === '07:00' && $b['end'] === '08:00');
        $this->assertNotNull($closed, 'Expected a 07:00–08:00 block.');
        $this->assertTrue($closed['closed'] ?? false);
    }
}
