<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlanCustomizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Op', 'email' => 'op@t.local', 'password' => Hash::make('secret123')]);
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0,
        ], $overrides));
    }

    private function owner(Plan $plan): Owner
    {
        return Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_admin_can_set_features_and_limits_on_a_plan(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/plans', [
                'name' => 'Pro', 'slug' => 'pro', 'max_members' => 100,
                'max_workspaces' => 5, 'max_rooms' => 25, 'max_products' => 30, 'price_per_month' => 200,
                'sort_order' => 1, 'features' => ['workspace', 'booking'],
            ]);

        $plan = Plan::where('slug', 'pro')->firstOrFail();
        // Creating a plan now opens its detail page.
        $response->assertRedirect(route('admin.plans.show', $plan->id));
        $this->assertEqualsCanonicalizing(['workspace', 'booking'], $plan->features);
        $this->assertSame(5, $plan->max_workspaces);
        $this->assertSame(25, $plan->max_rooms);
    }

    public function test_plan_default_features_apply_to_an_owner(): void
    {
        $owner = $this->owner($this->plan(['features' => ['hotspot', 'booking']]));

        $owner->applyPlanFeatures();
        $owner = $owner->fresh();

        $this->assertTrue($owner->hasFeature('hotspot'));
        $this->assertTrue($owner->hasFeature('booking'));
        $this->assertFalse($owner->hasFeature('workspace'));
    }

    public function test_workspace_limit_is_enforced(): void
    {
        $owner = $this->owner($this->plan(['max_workspaces' => 1, 'features' => ['workspace']]));
        $owner->enableFeature('workspace');

        $this->actingAs($owner, 'owner')->post('/workspaces', ['name' => 'Branch 1'])->assertRedirect();
        $this->assertSame(1, $owner->workspaces()->count());

        $this->actingAs($owner, 'owner')->post('/workspaces', ['name' => 'Branch 2'])->assertSessionHas('error');
        $this->assertSame(1, $owner->workspaces()->count());
    }

    public function test_room_limit_is_enforced(): void
    {
        $owner = $this->owner($this->plan(['max_rooms' => 1, 'max_workspaces' => 0, 'features' => ['workspace']]));
        $owner->enableFeature('workspace');
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        $room = ['name' => 'R1', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 50];
        $this->actingAs($owner, 'owner')->post("/workspaces/{$ws->id}/rooms", $room)->assertRedirect();
        $this->assertSame(1, $owner->rooms()->count());

        $room['name'] = 'R2';
        $this->actingAs($owner, 'owner')->post("/workspaces/{$ws->id}/rooms", $room)->assertSessionHas('error');
        $this->assertSame(1, $owner->rooms()->count());
    }

    public function test_zero_limit_means_unlimited(): void
    {
        $owner = $this->owner($this->plan(['max_workspaces' => 0]));
        $this->assertTrue($owner->canAddMoreWorkspaces());
        $this->assertNull($owner->remainingWorkspaceSlots());
    }
}
