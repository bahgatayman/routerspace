<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A hotspot owner whose router settings are only partly filled in (host and
 * username, no password — both forms allow an empty password) must still get
 * their dashboard and member pages; router calls fail softly instead of a
 * TypeError (500).
 */
class RouterConfigDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $router): Owner
    {
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create([
            'name' => 'Hotspot', 'slug' => 'hs-'.uniqid(), 'max_members' => 50, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['hotspot', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create($router + [
            'name' => 'Router Owner', 'email' => 'router'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Router Co',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        $owner->enableFeature('hotspot');
        $owner->enableFeature('booking');

        // Like a real request: the owner comes back from the database (column defaults applied).
        return $owner->fresh();
    }

    public function test_dashboard_survives_a_router_without_password(): void
    {
        // 127.0.0.1:1 refuses immediately, so no slow network wait in tests.
        $owner = $this->owner(['mikrotik_host' => '127.0.0.1', 'mikrotik_port' => 1, 'mikrotik_username' => 'admin', 'mikrotik_password' => null]);

        $this->actingAs($owner, 'owner')->get('/dashboard')->assertOk();
        $this->actingAs($owner, 'owner')->get('/sessions')->assertOk();
    }

    public function test_dashboard_survives_a_fully_configured_but_unreachable_router(): void
    {
        $owner = $this->owner(['mikrotik_host' => '127.0.0.1', 'mikrotik_port' => 1, 'mikrotik_username' => 'admin', 'mikrotik_password' => 'x']);

        $this->actingAs($owner, 'owner')->get('/dashboard')->assertOk();
    }
}
