<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\SubscriptionRequest;
use App\Services\SubscriptionRenewalService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DefaultPlanSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function registerOwner(string $email = 'new@t.local'): Owner
    {
        $this->post('/register', [
            'name' => 'New Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'New Space',
        ])->assertRedirect('/dashboard');

        return Owner::where('email', $email)->firstOrFail();
    }

    private function expiredOwner(): Owner
    {
        return Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'is_active' => true,
            'subscription_starts_at' => now()->subMonths(2), 'subscription_expires_at' => now()->subDay(),
        ]);
    }

    public function test_new_owner_starts_on_free_plan_with_a_full_one_month_trial_from_registration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 14:30:00'));

        $owner = $this->registerOwner();
        $free = Plan::where('slug', 'free')->firstOrFail();

        $this->assertSame($free->id, $owner->plan_id);
        $this->assertTrue($owner->isSubscriptionActive());
        $this->assertTrue($owner->subscription_starts_at->equalTo(Carbon::parse('2026-10-15 14:30:00')));
        $this->assertTrue(
            $owner->subscription_expires_at->equalTo(Carbon::parse('2026-11-15 14:30:00')),
            'A mid-month registration should expire exactly one month later, same day and time.'
        );

        // Free plan's default features come along for the ride.
        $this->assertTrue($owner->hasFeature('workspace'));
        $this->assertTrue($owner->hasFeature('booking'));
    }

    public function test_trial_registered_on_the_last_day_of_a_31_day_month_expires_on_the_30th_of_the_next(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 09:00:00'));

        $owner = $this->registerOwner();

        // November only has 30 days — must clamp, not overflow into December.
        $this->assertTrue($owner->subscription_expires_at->equalTo(Carbon::parse('2026-11-30 09:00:00')));
    }

    public function test_trial_registered_on_january_31_in_a_non_leap_year_expires_on_february_28(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00')); // 2026 is not a leap year

        $owner = $this->registerOwner();

        $this->assertTrue($owner->subscription_expires_at->equalTo(Carbon::parse('2026-02-28 10:00:00')));
    }

    public function test_trial_registered_on_january_31_in_a_leap_year_expires_on_february_29(): void
    {
        Carbon::setTestNow(Carbon::parse('2028-01-31 10:00:00')); // 2028 is a leap year

        $owner = $this->registerOwner();

        $this->assertTrue($owner->subscription_expires_at->equalTo(Carbon::parse('2028-02-29 10:00:00')));
    }

    public function test_trial_is_active_right_up_to_its_expiry_instant_and_inactive_just_after(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 14:30:00'));

        $owner = $this->registerOwner();
        $expiresAt = $owner->subscription_expires_at;

        Carbon::setTestNow($expiresAt->copy()->subSecond());
        $this->assertTrue($owner->fresh()->isSubscriptionActive(), 'Still active one second before expiry.');

        Carbon::setTestNow($expiresAt->copy()->addSecond());
        $this->assertFalse($owner->fresh()->isSubscriptionActive(), 'No longer active one second after expiry.');
    }

    public function test_choosing_the_free_plan_activates_instantly_without_a_request(): void
    {
        $owner = $this->expiredOwner();
        $free = Plan::where('slug', 'free')->firstOrFail();

        $this->actingAs($owner, 'owner')
            ->post('/subscription/request', ['plan_id' => $free->id, 'months' => 1])
            ->assertRedirect('/dashboard');

        $this->assertDatabaseCount('subscription_requests', 0);
        $this->assertTrue($owner->fresh()->isSubscriptionActive());
        $this->assertSame($free->id, $owner->fresh()->plan_id);
    }

    public function test_choosing_a_paid_plan_still_creates_a_pending_request(): void
    {
        $owner = $this->expiredOwner();
        $paid = Plan::where('slug', 'growth')->firstOrFail();

        $this->actingAs($owner, 'owner')
            ->post('/subscription/request', ['plan_id' => $paid->id, 'months' => 3])
            ->assertRedirect();

        $this->assertDatabaseHas('subscription_requests', [
            'owner_id' => $owner->id,
            'plan_id' => $paid->id,
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);
        // Paid plan is not active until an admin approves it.
        $this->assertFalse($owner->fresh()->isSubscriptionActive());
    }

    public function test_renewing_a_subscription_assigns_the_plans_features(): void
    {
        $owner = $this->expiredOwner();
        $plan = Plan::where('slug', 'growth')->firstOrFail(); // hotspot, workspace, booking, sales
        $admin = Admin::create(['name' => 'Op', 'email' => 'op@t.local', 'password' => Hash::make('secret123')]);

        app(SubscriptionRenewalService::class)->renew($owner, $plan, 1, $admin);

        $owner->refresh();
        foreach (['hotspot', 'workspace', 'booking', 'sales'] as $feature) {
            $this->assertTrue($owner->hasFeature($feature), "Expected the {$feature} feature after renewal.");
        }
    }
}
