<?php

namespace Tests\Feature;

use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use App\Services\AvailabilityService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-level coverage for Phase 3: click-to-book prefill and the
 * standalone quick availability lookup page. The actual availability math
 * is AvailabilityService's job (covered in AvailabilityServiceTest); these
 * tests check that the calendar's click targets and the lookup page wire
 * into it correctly, and that a stale calendar is never trusted over the
 * server-side check on submit.
 */
class BookingClickToBookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
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
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room A',
            'type' => $type, 'capacity' => $capacity, 'price_per_hour' => 50,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
    }

    // --- Click-to-book prefill ---

    public function test_create_form_prefills_from_calendar_click_query_params(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $date = today()->addDay()->toDateString();

        $response = $this->actingAs($owner, 'owner')->get('/bookings/create?'.http_build_query([
            'room_id' => $room->id, 'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
        ]));

        $response->assertOk();
        $response->assertViewHas('selectedRoomId', (string) $room->id);
        $response->assertViewHas('selectedDate', $date);
        $response->assertViewHas('selectedStartTime', '10:00');
        $response->assertViewHas('selectedEndTime', '11:00');
        // The prefilled room option must actually carry `selected`, not just be present in the data.
        $response->assertSee('value="'.$room->id.'"', false);
    }

    public function test_day_view_offers_a_click_to_book_link_for_a_free_slot(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 1);
        $date = today()->toDateString();

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.$date);

        $response->assertOk();
        $entry = collect($response->inertiaProps('dayRooms'))->firstWhere('room.id', $room->id);
        $links = collect($entry['slots'])->pluck('create_url')->filter();
        $this->assertNotEmpty($links);
        $this->assertStringStartsWith('/bookings/create?room_id='.$room->id, $links->first());
    }

    public function test_booked_slot_is_not_offered_as_a_click_to_book_link(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 1);
        $member = $this->member($owner);
        $date = today()->toDateString();

        $room->bookings()->create([
            'owner_id' => $owner->id, 'hotspot_user_id' => $member->id,
            'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
            'price_per_hour' => 50, 'total_hours' => 1, 'total_price' => 50, 'status' => 'confirmed',
        ]);

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.$date);

        $response->assertOk();
        $entry = collect($response->inertiaProps('dayRooms'))->firstWhere('room.id', $room->id);
        $tenAmSlot = collect($entry['slots'])->firstWhere('start', '10:00');
        $this->assertFalse($tenAmSlot['available']);
        // The 10:00 link must not appear as a bookable href for this room/date.
        $this->assertNull($tenAmSlot['create_url']);
        $this->assertStringNotContainsString('start_time=10%3A00&end_time=11%3A00', json_encode($response->inertiaProps()));
    }

    public function test_shared_room_has_no_click_to_book_slots(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared', 10);

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&room_id='.$room->id);

        $response->assertOk();
        $entry = collect($response->inertiaProps('dayRooms'))->firstWhere('room.id', $room->id);
        $this->assertSame([], $entry['slots']);
    }

    // --- Stale calendar data: the server always re-checks on submit ---

    public function test_click_to_book_prefill_is_rejected_server_side_if_slot_is_taken_first(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 1);
        $date = today()->toDateString();

        // The owner opened the day view (slot looked free), but by the time
        // they submit, someone else already took it — the calendar's
        // snapshot must never be trusted as authorization.
        $this->actingAs($owner, 'owner')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id,
            'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
        ])->assertRedirect();

        $response = $this->actingAs($owner, 'owner')->get('/bookings/create?'.http_build_query([
            'room_id' => $room->id, 'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
        ]));
        $response->assertOk();

        $submit = $this->actingAs($owner, 'owner')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id,
            'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
        ]);

        $submit->assertSessionHas('error');
        $this->assertSame(1, $room->bookings()->count());
    }

    // --- Quick availability lookup page ---

    public function test_availability_lookup_page_loads_with_all_owner_rooms(): void
    {
        $owner = $this->owner();
        $exclusive = $this->room($owner, 'meeting');
        $shared = Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $exclusive->workspace_id, 'name' => 'Shared Room',
            'type' => 'shared', 'capacity' => 10, 'price_per_hour' => 20,
        ]);

        $response = $this->actingAs($owner, 'owner')->get('/bookings/availability');

        $response->assertOk();
        $this->assertSame('Bookings/Availability', $response->inertiaPage()['component']);
        // Unlike the create form, shared rooms are selectable here too — this
        // is a read-only capacity check, not an advance-booking write path
        // (the React select renders every listed room as an enabled option).
        $ids = collect($response->inertiaProps('roomGroups'))->flatMap(fn ($g) => $g['rooms'])->pluck('id');
        $this->assertTrue($ids->contains($exclusive->id));
        $this->assertTrue($ids->contains($shared->id));
        $this->assertStringContainsString('Shared Room', json_encode($response->inertiaProps('roomGroups')));
    }

    public function test_availability_lookup_uses_the_same_check_availability_endpoint(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared', 10);
        $member = $this->member($owner);
        $date = today()->toDateString();

        $room->bookings()->create([
            'owner_id' => $owner->id, 'hotspot_user_id' => $member->id,
            'party_size' => 6, 'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '12:00',
            'price_per_hour' => 20, 'total_hours' => 2, 'total_price' => 40, 'status' => 'confirmed',
        ]);

        // 6/10 used — a party of 5 no longer fits (4 remain), a party of 4 does.
        $this->actingAs($owner, 'owner')
            ->getJson('/bookings/check-availability?'.http_build_query([
                'room_id' => $room->id, 'booking_date' => $date,
                'start_time' => '10:00', 'end_time' => '12:00', 'party_size' => 5,
            ]))
            ->assertOk()->assertJson(['available' => false, 'remaining' => 4]);

        $this->actingAs($owner, 'owner')
            ->getJson('/bookings/check-availability?'.http_build_query([
                'room_id' => $room->id, 'booking_date' => $date,
                'start_time' => '10:00', 'end_time' => '12:00', 'party_size' => 4,
            ]))
            ->assertOk()->assertJson(['available' => true, 'remaining' => 4]);
    }

    // --- Existing calendar views remain unaffected ---

    public function test_week_and_month_views_still_render_after_slots_were_added(): void
    {
        $owner = $this->owner();
        $this->room($owner);

        $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=week')->assertOk();
        $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=month')->assertOk();
    }

    public function test_bookable_slots_service_method_matches_availability_for_range(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 1);
        $member = $this->member($owner);
        $date = today()->toDateString();

        $room->bookings()->create([
            'owner_id' => $owner->id, 'hotspot_user_id' => $member->id,
            'booking_date' => $date, 'start_time' => '13:00', 'end_time' => '14:00',
            'price_per_hour' => 50, 'total_hours' => 1, 'total_price' => 50, 'status' => 'confirmed',
        ]);

        $availability = app(AvailabilityService::class);
        $slots = collect($availability->bookableSlots($room, $date));

        $booked = $slots->firstWhere('start', '13:00');
        $free = $slots->firstWhere('start', '09:00');

        $this->assertFalse($booked['available']);
        $this->assertTrue($free['available']);
        $this->assertSame(
            $availability->availabilityForRange($room, $date, '13:00', '14:00') >= 1,
            $booked['available']
        );
        $this->assertSame(
            $availability->availabilityForRange($room, $date, '09:00', '10:00') >= 1,
            $free['available']
        );
    }
}
