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
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Check Availability over a single day or a date range: one request, per
 * room per day, with the exact conflicts — built on AvailabilityService's
 * existing overlap/capacity rules.
 */
class AvailabilityRangeTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00'));
        $this->owner = $this->makeOwner();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function makeOwner(): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0,
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace', 'booking'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(string $name, string $type = 'meeting', int $capacity = 6, ?Owner $owner = null): Room
    {
        $owner ??= $this->owner;
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name, 'type' => $type, 'capacity' => $capacity, 'price_per_hour' => 100]);
    }

    private function book(Room $room, string $date, string $start, string $end, int $party = 1, string $status = 'confirmed', string $who = 'Bahgat Ayman'): Booking
    {
        $member = HotspotUser::create(['owner_id' => $room->owner_id, 'name' => $who, 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);

        return Booking::create([
            'owner_id' => $room->owner_id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id, 'party_size' => $party,
            'booking_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'price_per_hour' => 100, 'total_hours' => 1, 'total_price' => 100, 'status' => $status,
        ]);
    }

    private function check(array $params)
    {
        return $this->actingAs($this->owner, 'owner')->getJson('/bookings/availability-range?'.http_build_query(array_merge(
            ['from' => '2026-10-05', 'to' => '2026-10-10', 'start_time' => '10:00', 'end_time' => '18:00'], $params)));
    }

    private function roomReport($res, Room $room): array
    {
        return collect($res->json('rooms'))->firstWhere('room_id', $room->id);
    }

    private function day(array $report, string $date): array
    {
        return collect($report['days'])->firstWhere('date', $date);
    }

    // ------------------------------------------------------------------ tests

    public function test_1_fully_available_range(): void
    {
        $a = $this->room('Room A');
        $res = $this->check([])->assertOk()->assertJson(['days' => 6]);
        $r = $this->roomReport($res, $a);

        $this->assertTrue($r['all_available']);
        $this->assertSame(6, $r['days_available']);
        $this->assertCount(6, $r['days']);
        $this->assertSame(['available'], array_values(array_unique(array_column($r['days'], 'status'))));
    }

    public function test_2_one_conflict_in_the_middle_with_who_and_when(): void
    {
        $b = $this->room('Room B');
        $this->book($b, '2026-10-06', '12:00', '15:00');

        $r = $this->roomReport($this->check([])->assertOk(), $b);
        $this->assertFalse($r['all_available']);
        $this->assertSame(5, $r['days_available']);
        $this->assertSame(1, $r['conflict_days']);

        $day = $this->day($r, '2026-10-06');
        $this->assertSame('unavailable', $day['status']);
        $this->assertSame([[
            'booking_id' => Booking::first()->id, 'start' => '12:00', 'end' => '15:00', 'status' => 'confirmed',
            'customer' => 'Bahgat Ayman', 'party_size' => 1, 'overlap_minutes' => 180,
        ]], $day['conflicts']);
        $this->assertSame('available', $this->day($r, '2026-10-05')['status']);
        $this->assertSame('available', $this->day($r, '2026-10-07')['status']);
    }

    public function test_3_multiple_conflicts_across_days(): void
    {
        $a = $this->room('Room A');
        $this->book($a, '2026-10-05', '09:00', '11:00');
        $this->book($a, '2026-10-07', '10:00', '18:00');
        $this->book($a, '2026-10-09', '17:00', '19:00');

        $r = $this->roomReport($this->check([])->assertOk(), $a);
        $this->assertSame(3, $r['days_available']);
        $this->assertSame(['2026-10-05', '2026-10-07', '2026-10-09'],
            collect($r['days'])->where('status', 'unavailable')->pluck('date')->values()->all());
        $this->assertSame(60, $this->day($r, '2026-10-05')['conflicts'][0]['overlap_minutes'], 'Only the part inside 10:00–18:00.');
    }

    public function test_4_partial_day_conflict_splits_the_window(): void
    {
        $a = $this->room('Room A');
        $this->book($a, '2026-10-06', '14:00', '16:00');

        $day = $this->day($this->roomReport($this->check([])->assertOk(), $a), '2026-10-06');
        $this->assertSame([
            ['start' => '10:00', 'end' => '14:00', 'used' => 0, 'available' => 1],
            ['start' => '14:00', 'end' => '16:00', 'used' => 1, 'available' => 0],
            ['start' => '16:00', 'end' => '18:00', 'used' => 0, 'available' => 1],
        ], $day['segments']);
    }

    public function test_5_multiple_rooms_compared_in_one_response(): void
    {
        $one = $this->room('Meeting Room 1');
        $two = $this->room('Meeting Room 2');
        $this->book($one, '2026-10-07', '14:00', '16:00');
        $this->book($two, '2026-10-06', '10:00', '13:00');

        $res = $this->check(['to' => '2026-10-08'])->assertOk();
        $this->assertCount(2, $res->json('rooms'));
        $this->assertSame('unavailable', $this->day($this->roomReport($res, $one), '2026-10-07')['status']);
        $this->assertSame('available', $this->day($this->roomReport($res, $one), '2026-10-06')['status']);
        $this->assertSame('unavailable', $this->day($this->roomReport($res, $two), '2026-10-06')['status']);
        $this->assertSame('available', $this->day($this->roomReport($res, $two), '2026-10-07')['status']);

        // Filter to one room.
        $this->assertCount(1, $this->check(['room_id' => $one->id])->assertOk()->json('rooms'));
    }

    public function test_6_shared_room_uses_seat_capacity_per_day(): void
    {
        $shared = $this->room('Shared Room', 'shared', 20);
        $this->book($shared, '2026-10-06', '11:00', '15:00', 6);
        $this->book($shared, '2026-10-08', '10:00', '18:00', 18);

        $r = $this->roomReport($this->check(['party_size' => 4])->assertOk(), $shared);
        $oct6 = $this->day($r, '2026-10-06');
        $this->assertEquals(['status' => 'limited', 'remaining' => 14, 'used' => 6, 'capacity' => 20],
            array_intersect_key($oct6, array_flip(['status', 'remaining', 'used', 'capacity'])));
        $this->assertSame(20, $this->day($r, '2026-10-07')['remaining']);
        $this->assertSame('unavailable', $this->day($r, '2026-10-08')['status'], '2 seats left, 4 people asked.');
        $this->assertSame('limited', $this->day($this->roomReport($this->check(['party_size' => 2]), $shared), '2026-10-08')['status']);
    }

    public function test_7_past_dates_in_the_range_are_marked_past(): void
    {
        $a = $this->room('Room A');
        $r = $this->roomReport($this->check(['from' => '2026-10-03', 'to' => '2026-10-06'])->assertOk(), $a);

        $this->assertSame(['past', 'past', 'available', 'available'], array_column($r['days'], 'status'));
        $this->assertSame(2, $r['days_available']);
        $this->assertFalse($r['all_available']);
    }

    public function test_8_single_day_same_start_and_end_date(): void
    {
        $a = $this->room('Room A');
        $res = $this->check(['from' => '2026-10-06', 'to' => '2026-10-06'])->assertOk()->assertJson(['days' => 1, 'to' => '2026-10-06']);
        $this->assertCount(1, $this->roomReport($res, $a)['days']);

        // "Single day" mode can omit `to`.
        $this->actingAs($this->owner, 'owner')->getJson('/bookings/availability-range?from=2026-10-06&start_time=10:00&end_time=18:00')
            ->assertOk()->assertJson(['days' => 1, 'to' => '2026-10-06']);
    }

    public function test_9_invalid_ranges_are_rejected(): void
    {
        $this->room('Room A');
        $this->check(['from' => '2026-10-10', 'to' => '2026-10-05'])->assertStatus(422)->assertJson(['message' => __('app.availability.errors.range_order')]);
        $this->check(['start_time' => '18:00', 'end_time' => '10:00'])->assertStatus(422)->assertJson(['message' => __('app.availability.errors.time_order')]);
        $this->check(['to' => '2026-12-31'])->assertStatus(422)->assertJson(['message' => __('app.availability.errors.range_too_long', ['max' => 31])]);
        $this->check(['from' => 'nope'])->assertStatus(422);
    }

    public function test_10_back_to_back_bookings_do_not_overlap_the_window(): void
    {
        $a = $this->room('Room A');
        $this->book($a, '2026-10-06', '08:00', '10:00'); // ends exactly at window start
        $this->book($a, '2026-10-06', '18:00', '20:00'); // starts exactly at window end

        $day = $this->day($this->roomReport($this->check([])->assertOk(), $a), '2026-10-06');
        $this->assertSame('available', $day['status']);
        $this->assertSame([], $day['conflicts']);
    }

    public function test_11_exact_boundaries_inside_the_window(): void
    {
        $a = $this->room('Room A');
        $this->book($a, '2026-10-06', '10:00', '12:00');
        $this->book($a, '2026-10-06', '12:00', '18:00');

        $day = $this->day($this->roomReport($this->check([])->assertOk(), $a), '2026-10-06');
        $this->assertSame('unavailable', $day['status']);
        $this->assertCount(2, $day['conflicts']);
        $this->assertSame([['start' => '10:00', 'end' => '18:00', 'used' => 1, 'available' => 0]], $day['segments'], 'Back-to-back bookings = one booked block.');
        $this->assertSame([120, 360], array_column($day['conflicts'], 'overlap_minutes'));
    }

    public function test_12_ranges_crossing_months(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 08:00:00'));
        $a = $this->room('Room A');
        $this->book($a, '2026-11-01', '12:00', '13:00');

        $r = $this->roomReport($this->check(['from' => '2026-10-29', 'to' => '2026-11-03'])->assertOk()->assertJson(['days' => 6]), $a);
        $this->assertSame(['2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03'], array_column($r['days'], 'date'));
        $this->assertSame('unavailable', $this->day($r, '2026-11-01')['status']);
    }

    public function test_13_no_room_available_for_the_whole_range(): void
    {
        $a = $this->room('Room A');
        $b = $this->room('Room B');
        $this->book($a, '2026-10-06', '12:00', '13:00');
        $this->book($b, '2026-10-09', '15:00', '16:00');

        $rooms = $this->check([])->assertOk()->json('rooms');
        $this->assertSame([false, false], array_column($rooms, 'all_available'));
        $this->assertSame([5, 5], array_column($rooms, 'days_available'));
    }

    public function test_statuses_open_sessions_and_working_hours_follow_the_existing_rules(): void
    {
        $a = $this->room('Room A');
        $this->book($a, '2026-10-06', '12:00', '13:00', 1, 'cancelled');
        $this->book($a, '2026-10-07', '12:00', '13:00', 1, 'no_show');
        $open = $this->book($a, '2026-10-05', '07:30', '07:31', 1, 'open');
        $open->update(['end_time' => null]);

        $r = $this->roomReport($this->check([])->assertOk(), $a);
        $this->assertSame('available', $this->day($r, '2026-10-06')['status'], 'Cancelled never blocks.');
        $this->assertSame('available', $this->day($r, '2026-10-07')['status'], 'No-show never blocks.');
        $oct5 = $this->day($r, '2026-10-05');
        $this->assertSame('unavailable', $oct5['status'], 'An open session (no end) occupies the room that day.');
        $this->assertNull($oct5['conflicts'][0]['end']);

        // Working hours: closed on Fridays (2026-10-09) → "closed".
        foreach (range(0, 6) as $dow) {
            WorkingHour::create(['owner_id' => $this->owner->id, 'day_of_week' => $dow, 'is_open' => $dow !== 5, 'open_time' => '09:00', 'close_time' => '22:00']);
        }
        $r = $this->roomReport($this->check([])->assertOk(), $a);
        $this->assertSame('closed', $this->day($r, '2026-10-09')['status'], 'Friday closed.');
        $this->assertSame('available', $this->day($r, '2026-10-08')['status']);
    }

    public function test_each_day_matches_what_a_booking_attempt_would_check(): void
    {
        $shared = $this->room('Shared', 'shared', 5);
        $meeting = $this->room('Meeting');
        $this->book($shared, '2026-10-06', '09:00', '11:00', 3);
        $this->book($shared, '2026-10-06', '16:00', '19:00', 1);
        $this->book($meeting, '2026-10-07', '17:59', '18:30');
        $svc = app(AvailabilityService::class);

        $res = $this->check([])->assertOk();
        foreach ([$shared, $meeting] as $room) {
            foreach ($this->roomReport($res, $room)['days'] as $day) {
                $this->assertSame($svc->availabilityForRange($room, $day['date'], '10:00', '18:00'), $day['remaining'], "{$room->name} {$day['date']}");
            }
        }
    }

    public function test_tenancy_and_the_page(): void
    {
        $other = $this->makeOwner();
        $theirs = $this->room('Theirs', 'meeting', 6, $other);
        $mine = $this->room('Mine');
        $this->book($theirs, '2026-10-06', '12:00', '13:00');

        $this->check(['room_id' => $theirs->id])->assertNotFound();
        $rooms = $this->check([])->assertOk()->json('rooms');
        $this->assertSame([$mine->id], array_column($rooms, 'room_id'), 'Only my rooms.');

        $this->actingAs($this->owner, 'owner')->get('/bookings/availability')->assertOk()
            ->assertSee(__('app.availability.mode_range'))->assertSee('availability-range', false);
    }
}
