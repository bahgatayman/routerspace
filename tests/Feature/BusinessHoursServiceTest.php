<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\WorkingHour;
use App\Services\BusinessHoursService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Working Hours Phase 1: schema + model + service only, no enforcement
 * wired into any booking/session write path yet. These tests exercise
 * BusinessHoursService in isolation — the overnight-window algorithm in
 * particular, since that's the one place genuinely tricky logic lives.
 *
 * Day-of-week numbering is Carbon's native 0=Sunday..6=Saturday throughout.
 */
class BusinessHoursServiceTest extends TestCase
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

        return Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
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

    // --- Unconfigured owner: fully unrestricted ---

    public function test_owner_with_no_configured_hours_is_unrestricted(): void
    {
        $owner = $this->owner();
        $service = app(BusinessHoursService::class);

        $this->assertFalse($service->hasConfiguredHours($owner));
        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-18', '02:00', '23:59'));
        $this->assertTrue($service->isOpenAt($owner, Carbon::parse('2026-08-18 03:00:00')));
        $this->assertSame([], $service->effectiveWindowForDate($owner, '2026-08-18'));
    }

    // --- Normal same-day window ---

    public function test_same_day_window_is_respected(): void
    {
        $owner = $this->owner();
        // 2026-08-18 is a Tuesday -> Carbon dayOfWeek = 2.
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);
        $service = app(BusinessHoursService::class);

        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-18', '10:00', '22:00'));
        $this->assertFalse($service->isWithinWorkingHours($owner, '2026-08-18', '09:00', '11:00'));
        $this->assertFalse($service->isWithinWorkingHours($owner, '2026-08-18', '21:00', '23:00'));
    }

    public function test_closed_day_rejects_everything(): void
    {
        $owner = $this->owner();
        $this->day($owner, 2, ['is_open' => false, 'open_time' => null, 'close_time' => null]);
        $service = app(BusinessHoursService::class);

        $this->assertFalse($service->isWithinWorkingHours($owner, '2026-08-18', '10:00', '11:00'));
        $this->assertSame([], $service->effectiveWindowForDate($owner, '2026-08-18'));
    }

    public function test_bounds_are_inclusive(): void
    {
        $owner = $this->owner();
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);
        $service = app(BusinessHoursService::class);

        // Starting exactly at open, ending exactly at close — both allowed.
        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-18', '10:00', '22:00'));
    }

    // --- Time strings of differing precision must still compare correctly ---

    public function test_bare_h_i_and_h_i_s_time_strings_compare_correctly(): void
    {
        // Bookings store start_time/end_time as bare 'H:i' (no cast, exactly
        // as submitted); WorkingHour rows in this test are stored as 'H:i:s'.
        // A raw string comparison would wrongly treat '10:00' as "less than"
        // '10:00:00' (a shorter string that's a prefix of a longer one sorts
        // first) even though they're the same instant — this must not
        // happen here, since toMinutes() normalizes both before comparing.
        $owner = $this->owner();
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);
        $service = app(BusinessHoursService::class);

        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-18', '10:00', '10:30'));
        $this->assertFalse($service->isWithinWorkingHours($owner, '2026-08-18', '09:00', '09:30'));
    }

    // --- Overnight algorithm: the four worked cases from the plan ---

    public function test_overnight_neighbor_case_non_overlapping_days(): void
    {
        $owner = $this->owner();
        // 2026-08-20 is a Thursday (dayOfWeek 4), 2026-08-21 is Friday (dayOfWeek 5).
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']); // Thu 22:00->02:00
        $this->day($owner, 5, ['open_time' => '10:00:00', 'close_time' => '22:00:00']); // Fri 10:00->22:00
        $service = app(BusinessHoursService::class);

        $segments = $service->effectiveWindowForDate($owner, '2026-08-21');
        $this->assertCount(2, $segments);
        $this->assertContains(['00:00:00', '02:00:00'], $segments);
        $this->assertContains(['10:00:00', '22:00:00'], $segments);

        // 01:00 Friday validates via Thursday's spillover.
        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-21', '00:30', '01:00'));
        // 15:00 Friday validates via Friday's own hours.
        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-21', '15:00', '16:00'));
        // Spanning the closed 02:00-10:00 gap is rejected even though both endpoints
        // individually fall inside *some* segment.
        $this->assertFalse($service->isWithinWorkingHours($owner, '2026-08-21', '01:00', '11:00'));
    }

    public function test_overnight_spillover_applies_even_when_the_next_day_is_closed(): void
    {
        $owner = $this->owner();
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']); // Thu overnight
        $this->day($owner, 5, ['is_open' => false, 'open_time' => null, 'close_time' => null]); // Fri closed

        $service = app(BusinessHoursService::class);

        // Friday's own row says closed, but 00:00-02:00 is genuinely Thursday's hours.
        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-21', '00:30', '01:30'));
        // Anything after the spillover ends is still correctly closed.
        $this->assertFalse($service->isWithinWorkingHours($owner, '2026-08-21', '10:00', '11:00'));

        $segments = $service->effectiveWindowForDate($owner, '2026-08-21');
        $this->assertSame([['00:00:00', '02:00:00']], $segments);
    }

    public function test_two_consecutive_overnight_days_do_not_interfere(): void
    {
        $owner = $this->owner();
        // 2026-08-22 is Saturday (dayOfWeek 6).
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']); // Thu overnight
        $this->day($owner, 5, ['open_time' => '22:00:00', 'close_time' => '02:00:00']); // Fri overnight
        $service = app(BusinessHoursService::class);

        // Saturday 01:00 must be covered by Friday's spillover only — Thursday is
        // never consulted when the target date is Saturday.
        $segments = $service->effectiveWindowForDate($owner, '2026-08-22');
        $this->assertContains(['00:00:00', '02:00:00'], $segments);
        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-22', '00:30', '01:30'));

        // No duplicate/merged segment from Thursday leaking two days forward.
        $this->assertCount(1, array_filter($segments, fn ($s) => $s[1] === '02:00:00'));
    }

    public function test_own_day_crossing_midnight_is_open_through_end_of_day(): void
    {
        $owner = $this->owner();
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']); // Thu overnight
        $service = app(BusinessHoursService::class);

        // 23:00 Thursday itself must be open (own-day segment truncated at 24:00, not 02:00).
        $this->assertTrue($service->isWithinWorkingHours($owner, '2026-08-20', '23:00', '23:59'));
    }

    // --- isOpenAt() / isOpenNow() ---

    public function test_is_open_at_respects_overnight_spillover(): void
    {
        $owner = $this->owner();
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']);
        $service = app(BusinessHoursService::class);

        $this->assertTrue($service->isOpenAt($owner, Carbon::parse('2026-08-21 01:00:00')));
        $this->assertFalse($service->isOpenAt($owner, Carbon::parse('2026-08-21 05:00:00')));
        $this->assertTrue($service->isOpenAt($owner, Carbon::parse('2026-08-20 23:00:00')));
    }

    public function test_is_open_now_uses_the_current_instant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 11:00:00')); // Tuesday, 11:00.
        $owner = $this->owner();
        $this->day($owner, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);
        $service = app(BusinessHoursService::class);

        $this->assertTrue($service->isOpenNow($owner));

        Carbon::setTestNow(Carbon::parse('2026-08-18 23:00:00'));
        $this->assertFalse($service->isOpenNow($owner));

        Carbon::setTestNow();
    }

    // --- Per-owner isolation ---

    public function test_hours_are_isolated_per_owner(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $this->day($ownerA, 2, ['open_time' => '10:00:00', 'close_time' => '22:00:00']);
        // Owner B has no configured hours at all.

        $service = app(BusinessHoursService::class);

        $this->assertFalse($service->isWithinWorkingHours($ownerA, '2026-08-18', '23:00', '23:30'));
        $this->assertTrue($service->isWithinWorkingHours($ownerB, '2026-08-18', '23:00', '23:30'));
    }

    // --- weeklyDisplayHourRange() — the dashboard heatmap's hour-axis trim ---

    public function test_weekly_display_hour_range_is_null_when_unconfigured(): void
    {
        $owner = $this->owner();
        $service = app(BusinessHoursService::class);

        $this->assertNull($service->weeklyDisplayHourRange($owner));
    }

    public function test_weekly_display_hour_range_matches_a_normal_daytime_schedule(): void
    {
        $owner = $this->owner();
        foreach (range(1, 5) as $weekday) { // Mon-Fri
            $this->day($owner, $weekday, ['open_time' => '09:00:00', 'close_time' => '22:00:00']);
        }
        $this->day($owner, 0, ['is_open' => false, 'open_time' => null, 'close_time' => null]); // Sun closed
        $this->day($owner, 6, ['is_open' => false, 'open_time' => null, 'close_time' => null]); // Sat closed
        $service = app(BusinessHoursService::class);

        $this->assertSame(['start' => 9, 'end' => 22], $service->weeklyDisplayHourRange($owner));
    }

    public function test_weekly_display_hour_range_unions_different_hours_per_weekday(): void
    {
        $owner = $this->owner();
        $this->day($owner, 1, ['open_time' => '09:00:00', 'close_time' => '17:00:00']); // Mon
        $this->day($owner, 5, ['open_time' => '18:00:00', 'close_time' => '23:00:00']); // Fri — later than Monday
        $service = app(BusinessHoursService::class);

        // Earliest open (9) across every day, latest close (23) across every day.
        $this->assertSame(['start' => 9, 'end' => 23], $service->weeklyDisplayHourRange($owner));
    }

    public function test_weekly_display_hour_range_rounds_partial_hours_outward(): void
    {
        $owner = $this->owner();
        $this->day($owner, 2, ['open_time' => '09:30:00', 'close_time' => '22:30:00']);
        $service = app(BusinessHoursService::class);

        // 09:30 still needs the 09:00 row; 22:30 still needs the 22:00 row shown.
        $this->assertSame(['start' => 9, 'end' => 23], $service->weeklyDisplayHourRange($owner));
    }

    public function test_weekly_display_hour_range_closing_exactly_on_the_hour_does_not_add_an_extra_row(): void
    {
        $owner = $this->owner();
        $this->day($owner, 2, ['open_time' => '09:00:00', 'close_time' => '22:00:00']);
        $service = app(BusinessHoursService::class);

        // Closes exactly at 22:00 — the 22:00 row (which would represent 22:00-23:00,
        // already closed) must not appear; end=22 means hours 0..21 are the last shown.
        $this->assertSame(22, $service->weeklyDisplayHourRange($owner)['end']);
    }

    public function test_weekly_display_hour_range_covers_an_overnight_schedules_spillover(): void
    {
        $owner = $this->owner();
        $this->day($owner, 4, ['open_time' => '22:00:00', 'close_time' => '02:00:00']); // Thu overnight only
        $service = app(BusinessHoursService::class);

        // Thursday's own segment runs 22:00->24:00 (sentinel); Friday's spillover
        // segment runs 00:00->02:00 — the union must cover both tails.
        $this->assertSame(['start' => 0, 'end' => 24], $service->weeklyDisplayHourRange($owner));
    }

    public function test_weekly_display_hour_range_is_null_when_every_day_is_configured_closed(): void
    {
        $owner = $this->owner();
        foreach (range(0, 6) as $dow) {
            $this->day($owner, $dow, ['is_open' => false, 'open_time' => null, 'close_time' => null]);
        }
        $service = app(BusinessHoursService::class);

        // Configured, but nothing is ever open — nothing to narrow to, so the
        // caller falls back to showing all 24 hours rather than a zero-width range.
        $this->assertNull($service->weeklyDisplayHourRange($owner));
    }
}
