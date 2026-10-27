<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Workspace;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calendar View redesign: the daily summary strip, the status filter (display
 * only — never affects AvailabilityService's own booked/available math), the
 * consolidated empty state, and the multi-use-room "also bookable as" hint.
 * The booked/available math itself stays AvailabilityServiceTest's job.
 */
class CalendarRedesignTest extends TestCase
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

    private function workspace(Owner $owner, string $name = 'Main'): Workspace
    {
        return Workspace::create(['owner_id' => $owner->id, 'name' => $name]);
    }

    private function room(Owner $owner, Workspace $workspace, string $type = 'meeting', int $capacity = 4, string $name = 'Room A'): Room
    {
        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => $name,
            'type' => $type, 'capacity' => $capacity, 'price_per_hour' => 50,
        ]);
    }

    private function member(Owner $owner, string $name = 'Member'): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => $name, 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
    }

    private function booking(Owner $owner, Room $room, HotspotUser $member, string $date, string $start, string $end, string $status = 'confirmed'): Booking
    {
        return $room->bookings()->create([
            'owner_id' => $owner->id, 'hotspot_user_id' => $member->id,
            'booking_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'price_per_hour' => 50, 'total_hours' => 2, 'total_price' => 100, 'status' => $status,
        ]);
    }

    // --- Daily summary ---

    public function test_daily_summary_counts_todays_bookings_active_sessions_and_pending_requests(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $room = $this->room($owner, $workspace);
        $sharedRoom = $this->room($owner, $workspace, 'shared', 6, 'Lounge');
        $member = $this->member($owner);

        // Today, counts toward "today's bookings".
        $this->booking($owner, $room, $member, '2026-11-02', '10:00', '12:00', 'confirmed');
        // Today but cancelled — must NOT count.
        $this->booking($owner, $room, $member, '2026-11-02', '14:00', '15:00', 'cancelled');
        // An open walk-in session right now.
        SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $sharedRoom->id, 'hotspot_user_id' => $member->id,
            'session_date' => '2026-11-02', 'start_time' => '09:30', 'opened_at' => '2026-11-02 09:30:00',
            'party_size' => 1, 'status' => 'open',
        ]);
        // A pending request for later this week.
        $this->booking($owner, $room, $member, '2026-11-04', '09:00', '10:00', 'pending');
        // A pending request in the past — must NOT count as a current request.
        $this->booking($owner, $room, $member, '2026-10-01', '09:00', '10:00', 'pending');

        $response = $this->actingAs($owner, 'owner')->get('/bookings/calendar');

        $response->assertOk();
        $response->assertViewHas('summary', [
            'todayBookings' => 1,
            'activeSessions' => 1,
            'pendingRequests' => 1,
        ]);
    }

    public function test_daily_summary_respects_the_workspace_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        $owner = $this->owner();
        $workspaceA = $this->workspace($owner, 'A');
        $workspaceB = $this->workspace($owner, 'B');
        $roomA = $this->room($owner, $workspaceA, 'meeting', 4, 'Room A');
        $roomB = $this->room($owner, $workspaceB, 'meeting', 4, 'Room B');
        $member = $this->member($owner);

        $this->booking($owner, $roomA, $member, '2026-11-02', '10:00', '12:00');
        $this->booking($owner, $roomB, $member, '2026-11-02', '10:00', '12:00');

        $response = $this->actingAs($owner, 'owner')->get('/bookings/calendar?workspace_id='.$workspaceA->id);

        $response->assertOk();
        $response->assertViewHas('summary.todayBookings', 1);
    }

    public function test_daily_summary_never_counts_another_owners_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        $owner = $this->owner();
        $other = $this->owner();
        $workspace = $this->workspace($other);
        $room = $this->room($other, $workspace);
        $member = $this->member($other);

        $this->booking($other, $room, $member, '2026-11-02', '10:00', '12:00');

        $response = $this->actingAs($owner, 'owner')->get('/bookings/calendar');

        $response->assertOk();
        $response->assertViewHas('summary', [
            'todayBookings' => 0,
            'activeSessions' => 0,
            'pendingRequests' => 0,
        ]);
    }

    // --- Status filter (display only) ---

    public function test_status_filter_narrows_the_displayed_bookings_without_changing_availability(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $room = $this->room($owner, $workspace, 'meeting', 1);
        $member = $this->member($owner);

        $this->booking($owner, $room, $member, today()->toDateString(), '10:00', '11:00', 'confirmed');
        $this->booking($owner, $room, $member, today()->toDateString(), '13:00', '14:00', 'pending');

        $all = $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=day&date='.today()->toDateString());
        $all->assertViewHas('dayRooms', fn ($rooms) => $rooms[0]['bookings']->count() === 2);

        $pendingOnly = $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=day&date='.today()->toDateString().'&status=pending');
        $pendingOnly->assertViewHas('dayRooms', function ($rooms) {
            return $rooms[0]['bookings']->count() === 1
                && $rooms[0]['bookings']->first()->status === 'pending'
                // The exclusive room is fully booked by two reservations
                // either way — the status filter must not touch this.
                && count($rooms[0]['blocks']) > 0;
        });
    }

    public function test_an_invalid_status_value_is_ignored(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'owner')->get('/bookings/calendar?status=not-a-real-status');

        $response->assertOk();
        $response->assertViewHas('status', null);
    }

    // --- Consolidated empty state ---

    public function test_a_single_empty_state_renders_once_for_several_idle_rooms_not_once_per_room(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $this->room($owner, $workspace, 'meeting', 1, 'Room A');
        $this->room($owner, $workspace, 'meeting', 1, 'Room B');
        $this->room($owner, $workspace, 'meeting', 1, 'Room C');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        $html = $response->getContent();
        $needle = __('app.empty.no_bookings_on_day');
        $this->assertSame(1, substr_count($html, $needle), 'Expected exactly one consolidated empty-state message for 3 idle rooms.');
    }

    public function test_empty_state_is_absent_when_at_least_one_room_has_a_booking(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $roomA = $this->room($owner, $workspace, 'meeting', 1, 'Room A');
        $this->room($owner, $workspace, 'meeting', 1, 'Room B');
        $member = $this->member($owner);
        $this->booking($owner, $roomA, $member, today()->toDateString(), '10:00', '11:00');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        $response->assertDontSee(__('app.empty.no_bookings_on_day'));
    }

    // --- Studio room type badge ---

    public function test_studio_room_gets_its_own_badge_color_not_the_gray_fallback(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $this->room($owner, $workspace, 'studio', 1, 'Podcast Studio');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        $response->assertSee('bg-pink-100', false);
    }

    // --- Previous/Next labels (regression: both used to say "Back") ---

    public function test_previous_and_next_calendar_links_use_distinct_labels(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'owner')->get('/bookings/calendar');

        $response->assertOk();
        $this->assertNotSame(__('app.common.previous'), __('app.common.next'));
        $response->assertSee(__('app.common.previous'));
        $response->assertSee(__('app.common.next'));
    }

    // --- Multi-use room hint (Phase 3D) ---

    public function test_also_bookable_as_hint_appears_for_a_sibling_use_of_the_same_physical_room(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $physical = $this->room($owner, $workspace, 'shared', 10, 'Open Floor');
        $trainingUse = Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Training Room',
            'type' => 'training', 'capacity' => 1, 'price_per_hour' => 80,
        ]);
        // physical_room_id is deliberately not fillable (only a future
        // RoomUseController may set it) — direct property assignment
        // bypasses that guard the same way such a controller eventually would.
        $trainingUse->physical_room_id = $physical->id;
        $trainingUse->save();

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        // The physical room's row should mention its sibling use, and vice versa.
        $response->assertSee(__('app.booking.also_bookable_as', ['names' => 'Training Room']));
        $response->assertSee(__('app.booking.also_bookable_as', ['names' => 'Open Floor']));
    }

    public function test_no_also_bookable_as_hint_for_an_ordinary_single_use_room(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $this->room($owner, $workspace, 'meeting', 1, 'Solo Room');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        $response->assertDontSee('also bookable as');
    }

    // --- Timeline block positioning (BookingController::assignTimelineLanes) ---

    public function test_a_single_bookings_lane_item_carries_its_own_real_start_and_end_minutes(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $room = $this->room($owner, $workspace, 'meeting', 1);
        $member = $this->member($owner);
        $this->booking($owner, $room, $member, today()->toDateString(), '10:00', '11:30');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        $response->assertViewHas('dayRooms', function ($rooms) {
            $items = $rooms[0]['laneItems'];

            return count($items) === 1
                && $items[0]['lane'] === 0
                && $items[0]['startMin'] === 10 * 60
                && $items[0]['endMin'] === 11 * 60 + 30;
        });
    }

    public function test_back_to_back_bookings_in_an_exclusive_room_share_one_lane(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $room = $this->room($owner, $workspace, 'meeting', 1);
        $member = $this->member($owner);
        $this->booking($owner, $room, $member, today()->toDateString(), '09:00', '10:00');
        $this->booking($owner, $room, $member, today()->toDateString(), '10:00', '11:00');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        $response->assertViewHas('dayRooms', function ($rooms) {
            $lanes = collect($rooms[0]['laneItems'])->pluck('lane')->unique();

            return $rooms[0]['laneCount'] === 1 && $lanes->all() === [0];
        });
    }

    public function test_overlapping_bookings_in_a_shared_room_are_assigned_separate_lanes(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $room = $this->room($owner, $workspace, 'shared', 10);
        $memberA = $this->member($owner, 'A');
        $memberB = $this->member($owner, 'B');
        $this->booking($owner, $room, $memberA, today()->toDateString(), '09:00', '11:00');
        $this->booking($owner, $room, $memberB, today()->toDateString(), '09:30', '10:30');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        $response->assertViewHas('dayRooms', function ($rooms) {
            $lanes = collect($rooms[0]['laneItems'])->pluck('lane')->sort()->values();

            return $rooms[0]['laneCount'] === 2 && $lanes->all() === [0, 1];
        });
    }

    public function test_an_open_session_booking_visually_extends_to_the_end_of_the_displayed_range(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $room = $this->room($owner, $workspace, 'meeting', 1);
        $member = $this->member($owner);
        $room->bookings()->create([
            'owner_id' => $owner->id, 'hotspot_user_id' => $member->id,
            'booking_date' => today()->toDateString(), 'start_time' => '10:00', 'end_time' => null,
            'price_per_hour' => 50, 'total_hours' => 0, 'total_price' => 0, 'status' => 'open',
        ]);

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        $response->assertViewHas('dayRooms', function ($rooms) {
            $item = $rooms[0]['laneItems'][0];

            // Never collapses to a zero/negative-width sliver, and never
            // exceeds the room's own displayed range for that day.
            return $item['endMin'] > $item['startMin']
                && $item['endMin'] <= $this->toMinutesForTest((string) end($rooms[0]['blocks'])['end']);
        });
    }

    private function toMinutesForTest(string $time): int
    {
        if (str_starts_with($time, '24:00')) {
            return 24 * 60;
        }
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($h * 60) + $m;
    }

    // --- Status filter must not affect block positioning, only which blocks render ---

    public function test_status_filtered_bookings_are_excluded_from_lane_items_too(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $room = $this->room($owner, $workspace, 'meeting', 1);
        $member = $this->member($owner);
        $this->booking($owner, $room, $member, today()->toDateString(), '10:00', '11:00', 'confirmed');
        $this->booking($owner, $room, $member, today()->toDateString(), '13:00', '14:00', 'pending');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString().'&status=pending');

        $response->assertOk();
        $response->assertViewHas('dayRooms', function ($rooms) {
            $items = $rooms[0]['laneItems'];

            return count($items) === 1 && $items[0]['booking']->status === 'pending';
        });
    }

    // --- Unified timeline scale (visual-polish pass) ---

    public function test_the_time_axis_is_rendered_once_shared_across_rooms_not_once_per_room(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $roomA = $this->room($owner, $workspace, 'meeting', 1, 'Room A');
        $roomB = $this->room($owner, $workspace, 'meeting', 1, 'Room B');
        $member = $this->member($owner);
        $this->booking($owner, $roomA, $member, today()->toDateString(), '09:00', '10:00');
        $this->booking($owner, $roomB, $member, today()->toDateString(), '09:00', '10:00');

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        // Before the shared-scale fix, each room drew its own axis, so an
        // hour label appeared once per room. It must now appear exactly
        // once no matter how many rooms share the view. "9 AM" (Carbon's
        // 'g A' format) never collides with a booking's own "09:00 AM"
        // time-range text, which always carries a leading zero and minutes.
        $this->assertSame(1, substr_count($response->getContent(), '9 AM'));
    }

    // --- Current-time indicator (visual-polish pass) ---

    public function test_current_time_indicator_shows_in_day_view_only_for_todays_own_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 14:30:00'));
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $this->room($owner, $workspace, 'meeting', 1);

        $today = $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=day&date=2026-11-02');
        $today->assertOk();
        $today->assertSee('w-px bg-red-500', false);

        $future = $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=day&date=2026-11-05');
        $future->assertOk();
        $future->assertDontSee('w-px bg-red-500', false);

        $past = $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=day&date=2026-10-30');
        $past->assertOk();
        $past->assertDontSee('w-px bg-red-500', false);
    }

    public function test_current_time_indicator_never_shows_in_week_view_even_on_todays_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 14:30:00'));
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $this->room($owner, $workspace, 'meeting', 1);

        $response = $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=week&date=2026-11-02');

        $response->assertOk();
        $response->assertDontSee('w-px bg-red-500', false);
    }

    // --- Sidebar row height must fit its own content (final-polish pass) ---

    public function test_a_single_lane_room_with_an_also_bookable_as_hint_gets_a_taller_row_not_a_clipped_one(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspace($owner);
        $physical = $this->room($owner, $workspace, 'shared', 10, 'Open Floor');
        $trainingUse = Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Training Room',
            'type' => 'training', 'capacity' => 1, 'price_per_hour' => 80,
        ]);
        $trainingUse->physical_room_id = $physical->id;
        $trainingUse->save();

        $response = $this->actingAs($owner, 'owner')
            ->get('/bookings/calendar?view=day&date='.today()->toDateString());

        $response->assertOk();
        preg_match_all('/height:\s*(\d+)px;"/', $response->getContent(), $matches);
        $heights = array_map('intval', $matches[1]);

        // Both rooms have exactly one lane (no bookings), yet at least one
        // row must be tall enough to fit the 3 stacked sidebar lines (name,
        // type+live, "also bookable as") rather than being clipped at the
        // bare 1-lane height.
        $this->assertNotEmpty($heights);
        $this->assertGreaterThanOrEqual(58, max($heights), 'Expected at least one row tall enough for a 3-line sidebar, got max height '.max($heights));
    }
}
