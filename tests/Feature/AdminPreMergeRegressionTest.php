<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Feature;
use App\Models\Owner;
use App\Models\Plan;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-merge checks for two Super Admin behaviours touched by the React
 * migration: the renew form (months vs custom date) and the per-business
 * feature toggles exposed on the Features page.
 */
class AdminPreMergeRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $this->admin = Admin::create(['name' => 'Root', 'email' => 'root@t.local', 'password' => bcrypt('secret123')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(string $name, string $expires = '2026-11-15'): Owner
    {
        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'max_members' => 50, 'price_per_month' => 300, 'is_active' => true,
            'sort_order' => 1, 'features' => ['booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);

        return Owner::create([
            'name' => $name, 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name,
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subMonth(), 'subscription_expires_at' => $expires,
        ]);
    }

    public function test_renewing_by_months_uses_the_months_entered(): void
    {
        $owner = $this->owner('Alpha');

        // What the React form now sends in "months" mode: no expires_at.
        $this->actingAs($this->admin, 'admin')
            ->post("/admin/owners/{$owner->id}/renew", ['plan_id' => $owner->plan_id, 'months' => 3])
            ->assertRedirect("/admin/owners/{$owner->id}/subscription");

        $this->assertSame('2027-02-15', $owner->fresh()->subscription_expires_at->toDateString());
    }

    public function test_renewing_to_a_custom_date_uses_that_date(): void
    {
        $owner = $this->owner('Bravo');

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/owners/{$owner->id}/renew", ['plan_id' => $owner->plan_id, 'expires_at' => '2027-01-31'])
            ->assertRedirect();

        $this->assertSame('2027-01-31', $owner->fresh()->subscription_expires_at->toDateString());
    }

    public function test_the_renew_form_sends_only_the_field_of_the_chosen_mode(): void
    {
        $jsx = file_get_contents(resource_path('js/Pages/Admin/Owners/Show.jsx'));
        $this->assertStringContainsString("mode === 'months' ? { months: d.months } : { expires_at: d.expires_at }", $jsx);
    }

    public function test_per_business_feature_toggle_is_admin_only_and_touches_only_that_business(): void
    {
        $a = $this->owner('Alpha');
        $b = $this->owner('Bravo');
        $feature = Feature::where('key', 'sales')->firstOrFail();

        // Owners (even of the same business) and guests cannot toggle.
        $this->post("/admin/owners/{$a->id}/features/{$feature->id}/toggle")->assertRedirect();
        $this->actingAs($a, 'owner')->post("/admin/owners/{$a->id}/features/{$feature->id}/toggle")->assertRedirect();
        $this->assertFalse($a->fresh()->features()->where('feature_id', $feature->id)->exists());

        // Admin toggles exactly one business.
        $this->actingAs($this->admin, 'admin')->post("/admin/owners/{$a->id}/features/{$feature->id}/toggle")->assertRedirect();
        $this->assertTrue($a->fresh()->features()->where('feature_id', $feature->id)->exists());
        $this->assertFalse($b->fresh()->features()->where('feature_id', $feature->id)->exists());

        // Unknown ids are 404, not a silent no-op.
        $this->actingAs($this->admin, 'admin')->post("/admin/owners/999999/features/{$feature->id}/toggle")->assertNotFound();
        $this->actingAs($this->admin, 'admin')->post("/admin/owners/{$a->id}/features/999999/toggle")->assertNotFound();
    }
}
