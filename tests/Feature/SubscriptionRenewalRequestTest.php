<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner-initiated renewal: pick a plan on the expired/plans screen, admin
 * approves, subscription is extended. No payment gateway is involved.
 */
class SubscriptionRenewalRequestTest extends TestCase
{
    use RefreshDatabase;

    private Plan $basic;
    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);

        $this->basic = Plan::create([
            'name' => 'Basic', 'slug' => 'basic', 'max_members' => 50,
            'price_per_month' => 100, 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->pro = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'max_members' => 300,
            'price_per_month' => 250, 'is_active' => true, 'sort_order' => 2,
        ]);
    }

    private function makeOwner(array $overrides = []): Owner
    {
        return Owner::create(array_merge([
            'name' => 'Owner', 'email' => 'o' . uniqid() . '@t.local', 'password' => 'password',
            'business_name' => 'Nile Works', 'plan_id' => $this->basic->id, 'is_active' => true,
            'subscription_starts_at' => now()->subYear(),
            'subscription_expires_at' => now()->subDays(5), // expired
        ], $overrides))->fresh();
    }

    private function makeAdmin(): Admin
    {
        return Admin::create([
            'name' => 'Boss', 'email' => 'a' . uniqid() . '@t.local', 'password' => 'password',
        ]);
    }

    public function test_expired_owner_sees_the_plans_instead_of_a_dead_end(): void
    {
        $owner = $this->makeOwner();

        // The panel is locked...
        $this->actingAs($owner, 'owner')->get('/dashboard')->assertRedirect(route('subscription.expired'));

        // ...but the expired screen now lists every active plan.
        // (the request form renders whenever plans exist and nothing is pending)
        $this->actingAs($owner, 'owner')->get('/subscription/expired')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Subscription/Expired')
                ->has('plans', 2)
                ->where('plans.0.name', 'Basic')
                ->where('plans.1.name', 'Pro')
                ->where('pendingRequest', null));
    }

    public function test_plans_page_shows_the_owner_where_they_stand(): void
    {
        $expiry = now()->addDays(12)->startOfDay();
        $owner  = $this->makeOwner(['subscription_expires_at' => $expiry]);

        $this->actingAs($owner, 'owner')->get('/subscription/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Subscription/Plans')
                ->where('owner.plan_name', 'Basic')                  // current plan name in the header
                ->where('owner.expires_at', $expiry->format('d M Y')) // when it runs out
                ->where('owner.used_slots', 0)                       // usage meter
                ->where('owner.max_slots', 50)
                ->has('plans', 2));                                  // picker + summary panel
    }

    public function test_duration_is_a_choice_of_terms_and_unlimited_limits_read_as_unlimited(): void
    {
        $owner = $this->makeOwner();

        // 0 on a plan limit means unlimited (Owner::canAddMoreWorkspaces etc.).
        $this->pro->update(['max_workspaces' => 0, 'max_rooms' => 5]);

        $res = $this->actingAs($owner, 'owner')->get('/subscription/plans')->assertOk();

        $res->assertInertia(fn (Assert $p) => $p->component('Subscription/Plans')
            ->where('monthOptions', [1, 3, 6, 12])
            ->where('plans.1.name', 'Pro')
            // Workspaces = 0 reads as unlimited; rooms keep their number.
            ->where('plans.1.limits.1.value', __('app.subscription.unlimited'))
            ->where('plans.1.limits.2.value', '5')
            // Totals are pre-computed per term so the summary updates without a round trip.
            ->where('plans.1.totals.1', 'ج.م 250.00')
            ->where('plans.1.totals.3', 'ج.م 750.00')
            ->where('plans.1.totals.12', 'ج.م 3,000.00'));
    }

    public function test_expired_owner_can_submit_a_renewal_request(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner, 'owner')
            ->post('/subscription/request', ['plan_id' => $this->pro->id, 'months' => 3, 'note' => 'Paid by Instapay'])
            ->assertRedirect();

        $this->assertDatabaseHas('subscription_requests', [
            'owner_id' => $owner->id,
            'plan_id'  => $this->pro->id,
            'months'   => 3,
            'amount'   => 750,          // 250 × 3, quoted at request time
            'status'   => SubscriptionRequest::STATUS_PENDING,
        ]);

        // Still locked out until an admin approves.
        $this->assertFalse($owner->refresh()->isSubscriptionActive());
    }

    public function test_only_one_request_can_be_open_at_a_time(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner, 'owner')->post('/subscription/request', ['plan_id' => $this->basic->id, 'months' => 1]);
        $this->actingAs($owner, 'owner')
            ->post('/subscription/request', ['plan_id' => $this->pro->id, 'months' => 6])
            ->assertSessionHas('error');

        $this->assertSame(1, SubscriptionRequest::where('owner_id', $owner->id)->count());
    }

    public function test_owner_can_cancel_and_then_request_again(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner, 'owner')->post('/subscription/request', ['plan_id' => $this->basic->id, 'months' => 1]);
        $req = SubscriptionRequest::where('owner_id', $owner->id)->firstOrFail();

        $this->actingAs($owner, 'owner')->delete("/subscription/request/{$req->id}")->assertRedirect();
        $this->assertSame(SubscriptionRequest::STATUS_CANCELLED, $req->refresh()->status);

        $this->actingAs($owner, 'owner')->post('/subscription/request', ['plan_id' => $this->pro->id, 'months' => 2]);
        $this->assertSame(1, SubscriptionRequest::where('owner_id', $owner->id)->pending()->count());
    }

    public function test_admin_approval_extends_the_subscription_and_records_payment(): void
    {
        $owner = $this->makeOwner();
        $admin = $this->makeAdmin();

        $this->actingAs($owner, 'owner')->post('/subscription/request', ['plan_id' => $this->pro->id, 'months' => 2]);
        $req = SubscriptionRequest::where('owner_id', $owner->id)->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post("/admin/subscription-requests/{$req->id}/approve")
            ->assertRedirect();

        $owner->refresh();
        $this->assertTrue($owner->isSubscriptionActive());
        $this->assertSame($this->pro->id, $owner->plan_id);

        $this->assertDatabaseHas('subscriptions', [
            'owner_id'    => $owner->id,
            'admin_id'    => $admin->id,
            'plan_id'     => $this->pro->id,
            'months'      => 2,
            'amount_paid' => 500,
        ]);

        $req->refresh();
        $this->assertSame(SubscriptionRequest::STATUS_APPROVED, $req->status);
        $this->assertSame($admin->id, $req->admin_id);
        $this->assertNotNull($req->handled_at);

        // The owner is back in.
        $this->actingAs($owner, 'owner')->get('/dashboard')->assertOk();
    }

    public function test_approving_twice_is_not_possible(): void
    {
        $owner = $this->makeOwner();
        $admin = $this->makeAdmin();

        $this->actingAs($owner, 'owner')->post('/subscription/request', ['plan_id' => $this->basic->id, 'months' => 1]);
        $req = SubscriptionRequest::where('owner_id', $owner->id)->firstOrFail();

        $this->actingAs($admin, 'admin')->post("/admin/subscription-requests/{$req->id}/approve")->assertRedirect();
        $this->actingAs($admin, 'admin')->post("/admin/subscription-requests/{$req->id}/approve")->assertNotFound();

        $this->assertSame(1, Subscription::where('owner_id', $owner->id)->count());
    }

    public function test_rejection_notifies_the_owner_and_leaves_them_expired(): void
    {
        $owner = $this->makeOwner();
        $admin = $this->makeAdmin();

        $this->actingAs($owner, 'owner')->post('/subscription/request', ['plan_id' => $this->pro->id, 'months' => 1]);
        $req = SubscriptionRequest::where('owner_id', $owner->id)->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post("/admin/subscription-requests/{$req->id}/reject", ['admin_note' => 'Payment not received'])
            ->assertRedirect();

        $req->refresh();
        $this->assertSame(SubscriptionRequest::STATUS_REJECTED, $req->status);
        $this->assertSame('Payment not received', $req->admin_note);
        $this->assertFalse($owner->refresh()->isSubscriptionActive());

        $this->assertDatabaseHas('notifications', [
            'owner_id' => $owner->id,
            'type'     => 'subscription_request_rejected',
        ]);
    }

    public function test_renewing_early_stacks_on_the_remaining_time(): void
    {
        $expiry = now()->addDays(20)->startOfDay();
        $owner  = $this->makeOwner(['subscription_expires_at' => $expiry]);
        $admin  = $this->makeAdmin();

        // Active owner reaches the plans page through the panel, not the expired screen.
        $this->actingAs($owner, 'owner')->get('/subscription/expired')->assertRedirect('/dashboard');
        $this->actingAs($owner, 'owner')->get('/subscription/plans')->assertOk();

        $this->actingAs($owner, 'owner')->post('/subscription/request', ['plan_id' => $this->basic->id, 'months' => 1]);
        $req = SubscriptionRequest::where('owner_id', $owner->id)->firstOrFail();
        $this->actingAs($admin, 'admin')->post("/admin/subscription-requests/{$req->id}/approve");

        // New expiry counts from the old one, not from today.
        $this->assertSame(
            $expiry->copy()->addMonth()->toDateString(),
            $owner->refresh()->subscription_expires_at->toDateString()
        );
    }

    public function test_inactive_plans_cannot_be_requested(): void
    {
        $owner  = $this->makeOwner();
        $hidden = Plan::create([
            'name' => 'Legacy', 'slug' => 'legacy', 'max_members' => 10,
            'price_per_month' => 5, 'is_active' => false, 'sort_order' => 9,
        ]);

        $this->actingAs($owner, 'owner')
            ->post('/subscription/request', ['plan_id' => $hidden->id, 'months' => 1])
            ->assertNotFound();

        $this->assertSame(0, SubscriptionRequest::count());
    }

    public function test_owner_cannot_cancel_another_owners_request(): void
    {
        $a = $this->makeOwner();
        $b = $this->makeOwner();

        $this->actingAs($a, 'owner')->post('/subscription/request', ['plan_id' => $this->basic->id, 'months' => 1]);
        $req = SubscriptionRequest::where('owner_id', $a->id)->firstOrFail();

        $this->actingAs($b, 'owner')->delete("/subscription/request/{$req->id}")->assertNotFound();
        $this->assertSame(SubscriptionRequest::STATUS_PENDING, $req->refresh()->status);
    }

    public function test_owners_cannot_reach_the_admin_queue(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner, 'owner')->get('/admin/subscription-requests')->assertRedirect();
        $this->get('/admin/subscription-requests')->assertRedirect();
    }

    public function test_admin_queue_lists_pending_requests(): void
    {
        $owner = $this->makeOwner();
        $admin = $this->makeAdmin();

        $this->actingAs($owner, 'owner')->post('/subscription/request', ['plan_id' => $this->pro->id, 'months' => 4]);

        $this->actingAs($admin, 'admin')->get('/admin/subscription-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/SubscriptionRequests/Index')
                ->has('pending', 1)
                ->where('pending.0.business', 'Nile Works')
                ->where('pending.0.plan', 'Pro')
                ->where('pending.0.months', 4));
    }
}
