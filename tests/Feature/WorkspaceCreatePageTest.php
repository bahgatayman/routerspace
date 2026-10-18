<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\OnboardingService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Create Workspace: name prefill (first workspace only), optional Google Maps
 * location picker with server-validated coordinates, plan limit, and the
 * first-workspace hand-off that also ends the first-visit Welcome Wizard.
 * Google is never called here — the picker is client-side; the server only
 * validates and stores what the form submits.
 */
class WorkspaceCreatePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function owner(int $maxWorkspaces = 0, string $businessName = 'Owner Name'): Owner
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace'], 'max_workspaces' => $maxWorkspaces, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Owner Name', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $businessName,
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('workspace');

        return $owner->fresh();
    }

    // ------------------------------------------------------------------ name prefill

    public function test_first_workspace_is_prefilled_with_the_space_name_given_at_onboarding(): void
    {
        $owner = $this->owner(businessName: 'Cairo Desk');

        $this->actingAs($owner, 'owner')->get('/workspaces/create')->assertOk()
            ->assertSee('value="Cairo Desk"', false)
            ->assertSee(__('app.workspace.form.name_from_space'));
    }

    public function test_the_sign_up_default_personal_name_is_not_used_as_a_workspace_name(): void
    {
        $owner = $this->owner(businessName: 'Owner Name'); // same as the owner's own name

        $html = $this->actingAs($owner, 'owner')->get('/workspaces/create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="name"[^>]*value=""/', $html);
        $this->assertStringNotContainsString(__('app.workspace.form.name_from_space'), $html);
    }

    public function test_additional_workspaces_start_with_an_empty_name(): void
    {
        $owner = $this->owner(businessName: 'Cairo Desk');
        Workspace::create(['owner_id' => $owner->id, 'name' => 'Cairo Desk']);

        $html = $this->actingAs($owner, 'owner')->get('/workspaces/create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="name"[^>]*value=""/', $html);
    }

    // ------------------------------------------------------------------ location

    public function test_without_a_maps_key_the_page_offers_manual_address_entry_only(): void
    {
        config(['services.google_maps.key' => null]);
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->get('/workspaces/create')->assertOk()
            ->assertSee('name="address"', false)->assertSee('name="city"', false)
            ->assertDontSee('ws-place-search', false)
            ->assertDontSee('maps.googleapis.com', false);
    }

    public function test_with_a_maps_key_the_search_picker_is_offered_and_loads_lazily(): void
    {
        config(['services.google_maps.key' => 'test-browser-key']);
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->get('/workspaces/create')->assertOk()
            ->assertSee('id="ws-place-search"', false)
            ->assertSee(__('app.workspace.form.search_ph'))
            ->assertSee(__('app.workspace.form.use_location'))
            ->assertSee('name="latitude"', false);
    }

    public function test_a_selected_location_is_saved_with_coordinates_and_place_id(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->post('/workspaces', [
            'name' => 'Downtown', 'address' => '12 Tahrir St, Cairo', 'city' => 'Cairo', 'phone' => '01012345678',
            'latitude' => '30.0444196', 'longitude' => '31.2357116', 'place_id' => 'ChIJ_test_place',
        ])->assertRedirect();

        $ws = Workspace::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame('12 Tahrir St, Cairo', $ws->address);
        $this->assertEqualsWithDelta(30.0444196, $ws->latitude, 0.0000001);
        $this->assertEqualsWithDelta(31.2357116, $ws->longitude, 0.0000001);
        $this->assertSame('ChIJ_test_place', $ws->place_id);
    }

    public function test_the_map_pin_is_optional_when_the_address_is_typed(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->post('/workspaces', $this->valid(['name' => 'No Pin', 'address' => 'Somewhere', 'city' => 'Giza']))->assertRedirect();
        $this->post('/workspaces', $this->valid(['name' => 'Bare']))->assertRedirect();

        $noPin = Workspace::where('name', 'No Pin')->firstOrFail();
        $this->assertSame('Giza', $noPin->city);
        $this->assertNull($noPin->latitude);
        $this->assertNotNull(Workspace::where('name', 'Bare')->first());
    }

    public function test_invalid_or_half_coordinates_are_rejected_and_a_lone_place_id_is_dropped(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');

        $this->post('/workspaces', $this->valid(['name' => 'A', 'latitude' => '200', 'longitude' => '31']))->assertSessionHasErrors('latitude');
        $this->post('/workspaces', $this->valid(['name' => 'B', 'latitude' => '30', 'longitude' => 'east']))->assertSessionHasErrors('longitude');
        $this->post('/workspaces', $this->valid(['name' => 'C', 'latitude' => '30']))->assertSessionHasErrors('longitude');
        $this->assertSame(0, Workspace::where('owner_id', $owner->id)->count());

        // A place id on its own is not trusted as a location.
        $this->post('/workspaces', $this->valid(['name' => 'D', 'place_id' => 'ChIJ_spoofed']))->assertRedirect();
        $this->assertNull(Workspace::where('name', 'D')->value('place_id'));
    }

    public function test_the_description_reopens_when_it_holds_a_value(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->from('/workspaces/create')
            ->post('/workspaces', $this->valid(['name' => '', 'description' => 'Quiet floor']))->assertSessionHasErrors('name');

        $this->get('/workspaces/create')->assertSee('<details class="ls-ws-more"  open', false)
            ->assertSee('Quiet floor')->assertSee('value="01012345678"', false);
    }

    // ------------------------------------------------------------------ required location + mobile

    public function test_address_city_and_mobile_are_required(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');

        foreach (['address', 'city', 'phone'] as $field) {
            $this->post('/workspaces', $this->valid([$field => '']))->assertSessionHasErrors($field);
        }
        $this->assertSame(0, Workspace::where('owner_id', $owner->id)->count());

        $html = $this->get('/workspaces/create')->getContent();
        foreach (['address', 'city', 'phone'] as $field) {
            $this->assertMatchesRegularExpression('/name="'.$field.'"[^>]*required/', $html);
        }
    }

    public function test_the_phone_must_be_an_egyptian_mobile_in_any_common_format(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');

        foreach (['0225551234', '12345', '01912345678', 'call me'] as $bad) {
            $this->post('/workspaces', $this->valid(['phone' => $bad]))
                ->assertSessionHasErrors(['phone' => __('app.workspace.form.phone_invalid')]);
        }
        foreach (['01012345678', '+201112345678', '0122 345 6789', '1512345678'] as $i => $good) {
            $this->post('/workspaces', $this->valid(['name' => 'Branch '.$i, 'phone' => $good]))->assertSessionHasNoErrors();
        }
        $this->assertSame(4, Workspace::where('owner_id', $owner->id)->count());
    }

    public function test_editing_cannot_clear_the_location_or_mobile(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main', 'address' => '1 St', 'city' => 'Cairo', 'phone' => '01012345678']);

        $this->actingAs($owner, 'owner')->put("/workspaces/{$ws->id}", ['name' => 'Main', 'address' => '', 'city' => '', 'phone' => ''])
            ->assertSessionHasErrors(['address', 'city', 'phone']);
        $this->assertSame('01012345678', $ws->fresh()->phone);

        $this->put("/workspaces/{$ws->id}", ['name' => 'Main HQ', 'address' => '2 St', 'city' => 'Giza', 'phone' => '+201112345678'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Giza', $ws->fresh()->city);
    }

    /** A complete, valid create payload; override per test. */
    private function valid(array $overrides = []): array
    {
        return array_merge(['name' => 'Downtown', 'address' => '12 Tahrir St', 'city' => 'Cairo', 'phone' => '01012345678'], $overrides);
    }

    // ------------------------------------------------------------------ plan limit + hand-off

    public function test_no_limit_banner_on_the_page_but_the_server_still_enforces_the_plan_limit(): void
    {
        $owner = $this->owner(1);
        Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        $html = $this->actingAs($owner, 'owner')->get('/workspaces/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('ls-banner', $html);
        $this->assertDoesNotMatchRegularExpression('/<button type="submit"[^>]*disabled/', $html);

        $this->post('/workspaces', $this->valid(['name' => 'Second']))->assertSessionHas('error', __('app.plan_limit.workspaces'));
        $this->assertSame(1, Workspace::where('owner_id', $owner->id)->count());
    }

    public function test_first_workspace_continues_to_its_first_room_and_later_ones_return_to_the_list(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->followingRedirects()->post('/workspaces', $this->valid(['name' => 'Downtown']))
            ->assertOk()->assertSee(__('app.workspace.add_room'))
            ->assertSee(__('app.workspace.form.created_first', ['name' => 'Downtown']), false);

        $this->post('/workspaces', $this->valid(['name' => 'Uptown']))
            ->assertRedirect(route('workspaces.index', ['workspace' => Workspace::where('name', 'Uptown')->value('id')]))
            ->assertSessionHas('success', __('app.workspace.form.created', ['name' => 'Uptown']));
    }

    // ------------------------------------------------------------------ welcome pop-up lifecycle

    public function test_creating_the_first_workspace_ends_the_welcome_wizard_for_good(): void
    {
        $owner = $this->owner();
        $this->assertNull($owner->onboarding_welcomed_at);

        $this->actingAs($owner, 'owner')->post('/workspaces', $this->valid(['name' => 'Downtown']))->assertRedirect();

        $this->assertNotNull($owner->fresh()->onboarding_welcomed_at);
        $this->actingAs($owner->fresh(), 'owner')->get('/dashboard')->assertOk()
            ->assertDontSee('id="welcome-wizard"', false)
            ->assertSee('id="onboarding-checklist"', false);
    }

    public function test_onboarding_stays_off_instead_of_nagging_when_its_migration_has_not_run(): void
    {
        $owner = $this->owner();
        Schema::table('owners', fn ($t) => $t->dropColumn(['onboarding_welcomed_at', 'onboarding_checklist_hidden_at']));
        $this->resetOnboardingAvailability();

        try {
            $this->actingAs($owner->fresh(), 'owner')->get('/dashboard')->assertOk()
                ->assertDontSee('id="welcome-wizard"', false)
                ->assertDontSee('id="onboarding-checklist"', false);
            // Dismissing can't crash either.
            $this->postJson('/onboarding/welcome/dismiss')->assertOk();
        } finally {
            $this->resetOnboardingAvailability();
        }
    }

    private function resetOnboardingAvailability(): void
    {
        $prop = new \ReflectionProperty(OnboardingService::class, 'available');
        $prop->setValue(null, null);
    }
}
