<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Working Hours Phase 5: the dashboard's "open now" badge —
 * BusinessHoursService::isOpenNow() surfaced as a small, read-only
 * indicator. No enforcement lives here, just a glance-able status.
 */
class DashboardWorkingHoursTest extends TestCase
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
            'features' => $features,
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
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

    public function test_badge_is_hidden_for_an_owner_with_no_configured_hours(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index')->where('workingHours', null));
    }

    public function test_badge_shows_open_now_within_configured_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 11:00:00')); // Tuesday, 11:00.
        $owner = $this->owner();
        WorkingHour::create([
            'owner_id' => $owner->id, 'day_of_week' => 2,
            'is_open' => true, 'open_time' => '10:00:00', 'close_time' => '22:00:00',
        ]);

        $this->actingAs($owner, 'owner')->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workingHours.isOpenNow', true));
    }

    public function test_badge_shows_closed_now_outside_configured_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 23:00:00')); // Tuesday, 23:00.
        $owner = $this->owner();
        WorkingHour::create([
            'owner_id' => $owner->id, 'day_of_week' => 2,
            'is_open' => true, 'open_time' => '10:00:00', 'close_time' => '22:00:00',
        ]);

        $this->actingAs($owner, 'owner')->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workingHours.isOpenNow', false));
    }

    public function test_badge_is_hidden_for_a_hotspot_only_owner(): void
    {
        $owner = $this->owner(['hotspot']);
        WorkingHour::create([
            'owner_id' => $owner->id, 'day_of_week' => 2,
            'is_open' => true, 'open_time' => '10:00:00', 'close_time' => '22:00:00',
        ]);

        $this->actingAs($owner, 'owner')->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index')->where('workingHours', null));
    }
}
