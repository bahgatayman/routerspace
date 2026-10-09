<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\AnalyticsPeriod;
use App\Services\BookingAnalyticsService;
use App\Services\BusinessHoursService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingAnalyticsService $bookingAnalytics;

    private BusinessHoursService $businessHours;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->bookingAnalytics = app(BookingAnalyticsService::class);
        $this->businessHours = app(BusinessHoursService::class);
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

    private function room(Owner $owner, string $name = 'Room A', string $type = 'meeting'): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name,
            'type' => $type, 'capacity' => 1, 'price_per_hour' => 50,
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
        float $hours,
        float $totalPrice,
        string $status = 'completed',
    ): Booking {
        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id,
            'hotspot_user_id' => $this->member($owner)->id,
            'party_size' => 1,
            'booking_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'price_per_hour' => $totalPrice / max($hours, 0.01), 'total_hours' => $hours, 'total_price' => $totalPrice,
            'status' => $status,
        ]);
    }

    private function period(string $start, string $end): AnalyticsPeriod
    {
        return AnalyticsPeriod::custom(Carbon::parse($start), Carbon::parse($end));
    }

    // --- bookingsCount ---

    public function test_bookings_count_can_exclude_cancelled(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-10', '09:00', '10:00', 1, 50, 'confirmed');
        $this->booking($owner, $room, '2026-08-10', '11:00', '12:00', 1, 50, 'cancelled');

        $period = $this->period('2026-08-01', '2026-08-31');

        $this->assertSame(2, $this->bookingAnalytics->bookingsCount($owner, $period));
        $this->assertSame(1, $this->bookingAnalytics->bookingsCount($owner, $period, excludeCancelled: true));
    }

    // --- statusBreakdown ---

    public function test_status_breakdown_is_zero_filled_for_every_known_status(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-10', '09:00', '10:00', 1, 50, 'completed');
        $this->booking($owner, $room, '2026-08-10', '11:00', '12:00', 1, 50, 'completed');
        $this->booking($owner, $room, '2026-08-10', '13:00', '14:00', 1, 50, 'cancelled');

        $breakdown = $this->bookingAnalytics->statusBreakdown($owner, $this->period('2026-08-01', '2026-08-31'));

        $this->assertSame([
            'pending' => 0, 'confirmed' => 0, 'checked_in' => 0, 'open' => 0,
            'completed' => 2, 'cancelled' => 1, 'no_show' => 0,
        ], $breakdown);
    }

    public function test_count_by_status_reads_a_single_status_from_the_breakdown(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-10', '09:00', '10:00', 1, 50, 'no_show');

        $period = $this->period('2026-08-01', '2026-08-31');

        $this->assertSame(1, $this->bookingAnalytics->countByStatus($owner, $period, 'no_show'));
        $this->assertSame(0, $this->bookingAnalytics->countByStatus($owner, $period, 'completed'));
    }

    // --- roomUtilization ---

    public function test_room_utilization_aggregates_hours_revenue_and_count_per_room(): void
    {
        $owner = $this->owner();
        $roomA = $this->room($owner, 'Room A');
        $roomB = $this->room($owner, 'Room B');

        $this->booking($owner, $roomA, '2026-08-10', '09:00', '11:00', 2, 100, 'completed');
        $this->booking($owner, $roomA, '2026-08-11', '09:00', '10:00', 1, 50, 'confirmed');
        $this->booking($owner, $roomA, '2026-08-12', '09:00', '10:00', 1, 50, 'pending'); // excluded status
        $this->booking($owner, $roomB, '2026-08-10', '09:00', '10:00', 1, 60, 'checked_in');

        $rows = $this->bookingAnalytics->roomUtilization($owner, $this->period('2026-08-01', '2026-08-31'), $this->businessHours)
            ->keyBy('room_id');

        $this->assertSame(3.0, $rows[$roomA->id]['hours_booked']);
        $this->assertSame(150.0, $rows[$roomA->id]['revenue']);
        $this->assertSame(2, $rows[$roomA->id]['bookings_count']);
        $this->assertSame(1.0, $rows[$roomB->id]['hours_booked']);
    }

    public function test_room_utilization_percent_is_null_without_configured_working_hours(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->booking($owner, $room, '2026-08-10', '09:00', '11:00', 2, 100);

        $rows = $this->bookingAnalytics->roomUtilization($owner, $this->period('2026-08-01', '2026-08-31'), $this->businessHours);

        $this->assertNull($rows->first()['utilization_percent']);
        $this->assertSame(2.0, $rows->first()['hours_booked']);
    }

    public function test_room_utilization_percent_is_computed_against_configured_working_hours(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        // A 2-day period, both days configured open 09:00-17:00 (8h each) = 16h total.
        foreach (['2026-08-10', '2026-08-11'] as $date) {
            WorkingHour::create([
                'owner_id' => $owner->id,
                'day_of_week' => Carbon::parse($date)->dayOfWeek,
                'is_open' => true, 'open_time' => '09:00:00', 'close_time' => '17:00:00',
            ]);
        }

        $this->booking($owner, $room, '2026-08-10', '09:00', '13:00', 4, 200); // 4h booked

        $rows = $this->bookingAnalytics->roomUtilization($owner, $this->period('2026-08-10', '2026-08-11'), $this->businessHours);

        // 4h booked / 16h open = 25%.
        $this->assertSame(25.0, $rows->first()['utilization_percent']);
    }

    public function test_most_and_least_utilized_room_rank_by_hours_when_no_working_hours_configured(): void
    {
        $owner = $this->owner();
        $roomA = $this->room($owner, 'Busy Room');
        $roomB = $this->room($owner, 'Quiet Room');

        $this->booking($owner, $roomA, '2026-08-10', '09:00', '15:00', 6, 300);
        $this->booking($owner, $roomB, '2026-08-10', '09:00', '11:00', 2, 100);

        $period = $this->period('2026-08-01', '2026-08-31');

        $this->assertSame($roomA->id, $this->bookingAnalytics->mostUtilizedRoom($owner, $period, $this->businessHours)['room_id']);
        $this->assertSame($roomB->id, $this->bookingAnalytics->leastUtilizedRoom($owner, $period, $this->businessHours)['room_id']);
    }

    // --- peakHours ---

    public function test_peak_hours_groups_by_start_hour_and_excludes_cancelled(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        $this->booking($owner, $room, '2026-08-10', '09:15', '10:00', 0.75, 40);
        $this->booking($owner, $room, '2026-08-10', '09:45', '10:15', 0.5, 30);
        $this->booking($owner, $room, '2026-08-11', '14:00', '15:00', 1, 50);
        $this->booking($owner, $room, '2026-08-11', '18:00', '19:00', 1, 50, 'cancelled');

        $peak = $this->bookingAnalytics->peakHours($owner, $this->period('2026-08-01', '2026-08-31'));

        $this->assertSame([9 => 2, 14 => 1], $peak);
    }

    // --- peakHoursByDayOfWeek ---

    public function test_peak_hours_by_day_of_week_groups_by_day_and_hour_excluding_cancelled_and_no_show(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        // 2026-08-10 is a Monday (dow 1), 2026-08-11 a Tuesday (dow 2), 2026-08-12 a Wednesday (dow 3).
        $this->booking($owner, $room, '2026-08-10', '09:15', '10:00', 0.75, 40);
        $this->booking($owner, $room, '2026-08-10', '09:45', '10:15', 0.5, 30);
        $this->booking($owner, $room, '2026-08-11', '14:00', '15:00', 1, 50);
        $this->booking($owner, $room, '2026-08-11', '18:00', '19:00', 1, 50, 'cancelled');
        $this->booking($owner, $room, '2026-08-12', '10:00', '11:00', 1, 50, 'no_show');

        $grid = $this->bookingAnalytics->peakHoursByDayOfWeek($owner, $this->period('2026-08-01', '2026-08-31'));

        $this->assertCount(7, $grid);
        $this->assertCount(24, $grid[1]);
        $this->assertSame(2, $grid[1][9]);
        $this->assertSame(1, $grid[2][14]);
        // Cancelled and no_show never contribute a count anywhere in the grid.
        $this->assertSame(0, $grid[2][18]);
        $this->assertSame(0, $grid[3][10]);
    }

    public function test_peak_hours_by_day_of_week_is_zero_filled_with_no_bookings(): void
    {
        $owner = $this->owner();

        $grid = $this->bookingAnalytics->peakHoursByDayOfWeek($owner, $this->period('2026-08-01', '2026-08-31'));

        $this->assertSame(array_fill(0, 7, array_fill(0, 24, 0)), $grid);
    }

    /**
     * No MySQL server is available in this test environment, so this
     * verifies the exact SQL fragment picked per driver instead of running
     * a live query — getDriverName() only reads config, it never opens a
     * connection, so switching database.default here is safe. This is what
     * directly guards the production bug: strftime('%w', ...) is SQLite-only
     * and throws "FUNCTION ... strftime does not exist" on MySQL.
     */
    public function test_day_of_week_sql_picks_the_right_expression_per_driver(): void
    {
        $method = new \ReflectionMethod(BookingAnalyticsService::class, 'dayOfWeekSql');
        $method->setAccessible(true);
        $originalDefault = config('database.default');

        try {
            config(['database.default' => 'sqlite']);
            $this->assertSame("CAST(strftime('%w', booking_date) AS INTEGER)", $method->invoke($this->bookingAnalytics));

            config(['database.default' => 'mysql']);
            $this->assertSame('(DAYOFWEEK(booking_date) - 1)', $method->invoke($this->bookingAnalytics));

            config(['database.default' => 'mariadb']);
            $this->assertSame('(DAYOFWEEK(booking_date) - 1)', $method->invoke($this->bookingAnalytics));

            config(['database.default' => 'pgsql']);
            $this->assertSame('EXTRACT(DOW FROM booking_date)::integer', $method->invoke($this->bookingAnalytics));
        } finally {
            config(['database.default' => $originalDefault]);
        }
    }
}
