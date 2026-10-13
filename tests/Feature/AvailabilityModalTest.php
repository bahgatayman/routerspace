<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Check availability" opens as a pop-up on the Bookings list and Calendar; the page stays as the fallback. */
class AvailabilityModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function owner(string $name): Owner
    {
        $plan = Plan::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => $name, 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name,
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $name): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => $name.' WS']);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name, 'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 100]);
    }

    public function test_bookings_list_and_calendar_open_the_availability_popup_with_own_rooms_only(): void
    {
        $alpha = $this->owner('Alpha');
        $bravo = $this->owner('Bravo');
        $this->room($alpha, 'Alpha Board');
        $this->room($bravo, 'Bravo Secret');

        foreach (['/bookings', '/bookings/calendar'] as $url) {
            $html = $this->actingAs($alpha, 'owner')->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-ls-open="availability-modal"', $html, $url);
            $this->assertStringContainsString('id="availability-modal"', $html, $url);
            $this->assertStringContainsString('id="av-form"', $html, $url);
            $this->assertStringContainsString('/bookings/availability-range', $html, $url);
            $this->assertStringContainsString('Alpha Board', $html, $url);
            $this->assertStringNotContainsString('Bravo Secret', $html, $url);
        }
    }

    public function test_the_standalone_page_still_works(): void
    {
        $alpha = $this->owner('Alpha');
        $this->room($alpha, 'Alpha Board');

        $this->actingAs($alpha, 'owner')->get('/bookings/availability')->assertOk()
            ->assertSee('id="av-form"', false)->assertSee('Alpha Board')
            ->assertDontSee('id="availability-modal"', false);
    }
}
