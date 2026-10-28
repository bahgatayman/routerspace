<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomPricingProfile;
use App\Models\Workspace;
use App\Services\RoomPricingService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hourly suffix (app.common.slash_hr): English unchanged ("EGP 120.00/ hr"),
 * Arabic now natural ("120.00 ج.م / ساعة"). Amounts and currency are untouched —
 * only the display text changes.
 */
class PriceSuffixLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('en');
        parent::tearDown();
    }

    private function room(array $attrs = []): Room
    {
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create(['name' => 'Free', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Karim', 'email' => 'k'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Biz',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('workspace');
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main', 'address' => '1 St', 'city' => 'Cairo']);

        return Room::create(array_merge(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Board', 'type' => 'meeting',
            'capacity' => 6, 'price_per_hour' => 120], $attrs));
    }

    public function test_translation_keys(): void
    {
        $this->assertSame('/ hr', __('app.common.slash_hr', [], 'en'), 'English unchanged');
        $this->assertSame(' / ساعة', __('app.common.slash_hr', [], 'ar'));
    }

    public function test_room_price_summary_in_both_locales(): void
    {
        $pricing = app(RoomPricingService::class);
        $hourly = $this->room();
        $people = Room::create(['owner_id' => $hourly->owner_id, 'workspace_id' => $hourly->workspace_id, 'name' => 'Team', 'type' => 'meeting', 'capacity' => 10,
            'price_per_hour' => 0, 'pricing_model' => 'people', 'pricing_rules' => ['people' => [1, 5], 'prices' => [[50], [80]]]]);
        $duration = Room::create(['owner_id' => $hourly->owner_id, 'workspace_id' => $hourly->workspace_id, 'name' => 'Pod', 'type' => 'office', 'capacity' => 2,
            'price_per_hour' => 0, 'pricing_model' => 'duration', 'pricing_rules' => ['durations' => [60, 120], 'prices' => [[90, 160]]]]);

        app()->setLocale('en');
        $this->assertSame('EGP 120.00/ hr', $pricing->summary($hourly));
        $this->assertSame(__('app.pricing.from', ['price' => 'EGP 50.00']).'/ hr', $pricing->summary($people));
        $this->assertSame(__('app.pricing.from', ['price' => 'EGP 90.00']), $pricing->summary($duration), 'No suffix for duration packages.');

        app()->setLocale('ar');
        $this->assertSame('120.00 ج.م / ساعة', $pricing->summary($hourly));
        $this->assertSame(__('app.pricing.from', ['price' => '50.00 ج.م']).' / ساعة', $pricing->summary($people));
        $this->assertStringNotContainsString('hr', $pricing->summary($hourly));

        // Amounts are locale-independent.
        $this->assertSame(120.0, $pricing->displayPrice($hourly));
        $this->assertSame(50.0, $pricing->displayPrice($people));
    }

    public function test_pricing_profile_label_in_both_locales(): void
    {
        $room = $this->room();
        $profile = RoomPricingProfile::create(['room_id' => $room->id, 'owner_id' => $room->owner_id, 'name' => 'Students', 'price_per_hour' => 60, 'is_active' => true, 'sort_order' => 1]);

        app()->setLocale('en');
        $this->assertStringContainsString('EGP 60.00/ hr', $profile->rateLabel());
        app()->setLocale('ar');
        $this->assertStringContainsString('60.00 ج.م / ساعة', $profile->rateLabel());
    }

    public function test_owner_rooms_page_and_public_api_show_the_arabic_suffix(): void
    {
        $room = $this->room();
        $owner = $room->owner;

        $this->actingAs($owner, 'owner')->withSession(['locale' => 'ar'])->get(route('workspaces.index', ['workspace' => $room->workspace_id]))
            ->assertOk()->assertSee('120.00 ج.م / ساعة');
        $this->actingAs($owner, 'owner')->withSession(['locale' => 'en'])->get(route('workspaces.index', ['workspace' => $room->workspace_id]))
            ->assertOk()->assertSee('EGP 120.00/ hr');

        $ws = $room->workspace;
        $ws->forceFill(['description' => 'Desk', 'publication_status' => Workspace::PUBLISHED, 'slug' => 'main'])->save();
        $ws->images()->create(['owner_id' => $owner->id, 'path' => 'workspace-images/x.jpg', 'is_primary' => true, 'sort_order' => 1]);
        auth('owner')->logout();

        $this->getJson('/api/v1/workspaces/main?lang=ar')->assertOk()
            ->assertJsonPath('data.rooms.0.price.display', '120.00 ج.م / ساعة')
            ->assertJsonPath('data.rooms.0.price.amount', 120)
            ->assertJsonPath('data.rooms.0.price.currency', 'EGP');
        $this->getJson('/api/v1/workspaces/main?lang=en')->assertOk()
            ->assertJsonPath('data.rooms.0.price.display', 'EGP 120.00/ hr');
    }
}
