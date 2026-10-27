<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Add Room as a three-step wizard (rooms/create): one form, the same fields
 * and endpoint as before, steps only showing/hiding parts of it. These tests
 * cover the server-rendered side (markup, payload, validation round-trips);
 * step navigation itself is client-side and checked in a browser.
 */
class RoomCreateWizardTest extends TestCase
{
    use RefreshDatabase;

    private const MATRIX = [
        'durations' => [['minutes' => 60], ['full_day' => true]],
        'people' => [['max' => 2], ['max' => 6]],
        'prices' => [[100, 500], [180, 900]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function owner(int $maxRooms = 0): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => $maxRooms, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function create(Owner $owner, Workspace $ws, array $fields)
    {
        return $this->actingAs($owner, 'owner')->from(route('rooms.create', $ws))->post(route('rooms.store', $ws), $fields);
    }

    /**
     * Submit, then follow the redirect back to the page — exactly what the
     * browser does — and return that page (it carries the flashed errors and
     * old input). The caller asserts on what the page shows.
     */
    private function failAndReopen(Owner $owner, Workspace $ws, array $fields)
    {
        $this->create($owner, $ws, $fields)->assertRedirect(route('rooms.create', $ws));

        return $this->actingAs($owner, 'owner')->get(route('rooms.create', $ws))->assertOk();
    }

    // ------------------------------------------------------------------ the page

    public function test_the_page_is_one_form_in_three_steps_with_every_existing_field_once(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);

        $html = $this->actingAs($owner, 'owner')->get(route('rooms.create', $ws))->assertOk()
            ->assertSee(__('app.workspace.wizard.steps.details'))
            ->assertSee(__('app.workspace.wizard.steps.pricing'))
            ->assertSee(__('app.workspace.wizard.steps.extras'))
            ->assertSee(__('app.workspace.wizard.step_of', ['current' => 1, 'total' => 3]))
            ->assertSee(__('app.pricing.question'))
            ->assertSee(__('app.pricing_profiles.section'))
            ->assertSee(__('app.plans.section'))
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-room-wizard data-start-step'), 'One wizard form.');
        // Field checks look inside the wizard form only (the layout has its own forms).
        $start = strpos($html, '<form method="POST" novalidate class="ls-wizard"');
        $form = substr($html, $start, strpos($html, '</form>', $start) - $start);
        $this->assertStringContainsString('action="'.route('rooms.store', $ws).'"', $html);
        foreach (['data-wizard-step="1"', 'data-wizard-step="2"', 'data-wizard-step="3"'] as $panel) {
            $this->assertSame(1, substr_count($html, $panel));
        }

        // The same request fields as before, each exactly once (the 4 models and 3 units are radios).
        $counts = ['name' => 1, 'type' => 1, 'capacity' => 1, 'description' => 1, 'price_per_hour' => 1, 'billing_buffer_minutes' => 1,
            'pricing_rules' => 1, 'pricing_profiles' => 1, 'plans' => 1, 'pricing_model' => 4, 'billing_unit' => 3, '_token' => 1];
        foreach ($counts as $field => $n) {
            // Real controls only (the pricing script also mentions some names in selectors).
            $this->assertSame($n, preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname="'.preg_quote($field, '/').'"/', $form), "name=\"$field\"");
        }
        foreach (['hourly', 'duration', 'people', 'people_duration'] as $model) {
            $this->assertStringContainsString('name="pricing_model" value="'.$model.'"', $html);
        }

        // No duplicate ids anywhere on the page.
        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $dupes = array_keys(array_filter(array_count_values($m[1]), fn ($c) => $c > 1));
        $this->assertSame([], $dupes, 'Duplicate ids: '.implode(', ', $dupes));

        // Server-rendered for no-JS: every panel present and not hidden; the
        // submit button is there; the wizard script hides what isn't current.
        $this->assertDoesNotMatchRegularExpression('/data-wizard-step="\d"[^>]*\bhidden\b/', $html);
        $this->assertStringContainsString('data-wizard-submit', $html);
        $this->assertStringContainsString('data-start-step="1"', $html);
        // The profile/plan editors keep their own (hidden JSON) payload and scripts.
        $this->assertStringContainsString("document.querySelector('[data-profiles]')", $html);
        $this->assertStringContainsString("document.querySelector('[data-plans]')", $html);
    }

    public function test_the_edit_page_is_the_same_wizard_with_every_step_open_and_the_room_filled_in(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Board Room', 'type' => 'meeting', 'capacity' => 6,
            'price_per_hour' => 0, 'pricing_model' => 'people_duration', 'pricing_rules' => self::MATRIX, 'description' => 'Projector']);
        $room->plans()->create(['owner_id' => $owner->id, 'name' => 'Team day', 'people' => 6, 'is_full_day' => true, 'price' => 900, 'sort_order' => 0]);

        $html = $this->actingAs($owner, 'owner')->get(route('rooms.edit', [$ws, $room]))->assertOk()
            ->assertSee(__('app.workspace.edit_room'))
            ->assertSee(__('app.workspace.wizard.edit_subtitle'))
            ->assertSee(__('app.workspace.wizard.save_changes'))
            ->assertSee(__('app.workspace.uses.title'), false) // the other-uses panel stays
            ->assertSee('id="room-photos"', false)             // Room Photos stays, below the form
            ->getContent();

        $start = strpos($html, '<form method="POST" novalidate class="ls-wizard"');
        $form = substr($html, $start, strpos($html, '</form>', $start) - $start);
        $this->assertStringContainsString('action="'.route('rooms.update', [$ws, $room]).'"', $form);
        $this->assertStringContainsString('name="_method" value="PUT"', $form);
        $this->assertStringContainsString('data-editing="1"', $form);
        $this->assertStringContainsString('data-reached="3"', $form, 'Every step is open.');
        $this->assertStringContainsString('value="Board Room"', $form);
        $this->assertStringContainsString('>Projector</textarea>', $form);
        $this->assertStringContainsString('<option value="meeting" selected>', $form);
        $this->assertStringContainsString('name="pricing_model" value="people_duration" checked', $form);
        $this->assertStringContainsString('value="'.e(json_encode(self::MATRIX)).'"', $form);
        $this->assertMatchesRegularExpression('/data-wizard-extra="plans"\s+open/', $form, 'A room with plans opens that section.');
        $this->assertDoesNotMatchRegularExpression('/data-wizard-extra="profiles"\s+open/', $form);

        // Same fields, each once; the photo uploads stay outside the room form.
        foreach (['name' => 1, 'type' => 1, 'capacity' => 1, 'description' => 1, 'pricing_rules' => 1, 'pricing_profiles' => 1, 'plans' => 1, 'pricing_model' => 4] as $field => $n) {
            $this->assertSame($n, preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname="'.$field.'"/', $form), "name=\"$field\"");
        }
        $this->assertStringNotContainsString('room-photos', $form);
        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $this->assertSame([], array_keys(array_filter(array_count_values($m[1]), fn ($c) => $c > 1)), 'No duplicate ids.');
    }

    public function test_saving_from_the_edit_wizard_updates_the_room_exactly_as_before(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Pod', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 90]);

        $this->actingAs($owner, 'owner')->from(route('rooms.edit', [$ws, $room]))->put(route('rooms.update', [$ws, $room]), [
            'name' => 'Pod XL', 'type' => 'meeting', 'capacity' => 6, 'description' => 'Bigger',
            'pricing_model' => 'duration', 'pricing_rules' => json_encode(['durations' => [['minutes' => 60]], 'prices' => [[150]]]),
            'pricing_profiles' => json_encode([['name' => 'Event', 'price_per_hour' => 400]]), 'plans' => '[]',
        ])->assertRedirect(route('workspaces.index', ['workspace' => $ws->id]))->assertSessionHas('success')->assertSessionHasNoErrors();

        $room->refresh();
        $this->assertSame(['Pod XL', 6, 'Bigger', 'duration'], [$room->name, $room->capacity, $room->description, $room->pricing_model]);
        $this->assertSame(['Event'], $room->pricingProfiles()->pluck('name')->all());

        // A failed save reopens the step with the problem, values kept.
        $this->actingAs($owner, 'owner')->from(route('rooms.edit', [$ws, $room]))->put(route('rooms.update', [$ws, $room]), [
            'name' => 'Pod XL', 'type' => 'meeting', 'capacity' => 6, 'pricing_model' => 'hourly', 'price_per_hour' => '',
        ])->assertRedirect(route('rooms.edit', [$ws, $room]));
        $this->actingAs($owner, 'owner')->get(route('rooms.edit', [$ws, $room]))
            ->assertSee('data-start-step="2"', false)
            ->assertSee('data-has-errors="1"', false)
            ->assertSee(__('app.workspace.wizard.fix_errors'));
        $this->assertSame('duration', $room->fresh()->pricing_model, 'Nothing saved by the failed attempt.');
    }

    public function test_step_one_errors_are_tied_to_their_fields(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Hall', 'type' => 'shared', 'capacity' => 10, 'price_per_hour' => 50]);

        // "Add another use": the name has a hint AND an error — one aria-describedby naming both.
        $this->actingAs($owner, 'owner')->from(route('rooms.uses.create', [$ws, $room]))
            ->post(route('rooms.uses.store', [$ws, $room]), ['name' => '', 'type' => 'castle', 'capacity' => 0, 'description' => str_repeat('x', 1001), 'price_per_hour' => 10]);
        $html = $this->actingAs($owner, 'owner')->get(route('rooms.uses.create', [$ws, $room]))->getContent();

        preg_match('/<input type="text" name="name"[^>]*>/s', $html, $name);
        $this->assertSame(1, substr_count($name[0], 'aria-describedby='), 'One aria-describedby attribute.');
        $this->assertStringContainsString('aria-describedby="room_name-hint room_name-error"', $name[0]);
        $this->assertStringContainsString('aria-describedby="room_type_hint room_type-error"', $html);
        $this->assertStringContainsString('aria-describedby="room_capacity-error"', $html);
        $this->assertStringContainsString('aria-describedby="room_description-error"', $html);
        foreach (['room_name-error', 'room_type-error', 'room_capacity-error', 'room_description-error'] as $id) {
            $this->assertStringContainsString('<p class="ls-error" id="'.$id.'">', $html);
        }
    }

    public function test_the_review_shows_the_price_grid_in_a_scrolling_frame(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);

        $html = $this->actingAs($owner, 'owner')->get(route('rooms.create', $ws))->assertOk()->getContent();

        // The review's read-only copy of the grid sits in the same sideways-scrolling
        // frame as the step-2 grid (.ls-pricing-grid-wrap: overflow-x auto).
        $this->assertStringContainsString("el('div', 'ls-pricing-grid-wrap ls-wizard-review-grid-wrap')", $html);
        $this->assertStringContainsString('frame.append(copy)', $html);
    }

    // ------------------------------------------------------------------ the four pricing models, unchanged

    public function test_every_pricing_model_creates_the_same_room_as_before(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);
        $base = ['type' => 'meeting', 'capacity' => 6, 'description' => 'Bright room'];

        $this->create($owner, $ws, $base + ['name' => 'Hourly', 'pricing_model' => 'hourly', 'price_per_hour' => 120])
            ->assertRedirect(route('workspaces.index', ['workspace' => $ws->id]))->assertSessionHas('success')->assertSessionHasNoErrors();
        $this->create($owner, $ws, $base + ['name' => 'Duration', 'pricing_model' => 'duration',
            'pricing_rules' => json_encode(['durations' => [['minutes' => 60], ['minutes' => 120]], 'prices' => [[100, 180]]])])->assertSessionHasNoErrors();
        $this->create($owner, $ws, $base + ['name' => 'People', 'pricing_model' => 'people',
            'pricing_rules' => json_encode(['people' => [['max' => 2], ['max' => 6]], 'prices' => [[80], [140]]])])->assertSessionHasNoErrors();
        $this->create($owner, $ws, $base + ['name' => 'Matrix', 'pricing_model' => 'people_duration',
            'pricing_rules' => json_encode(self::MATRIX)])->assertSessionHasNoErrors();

        $rooms = Room::where('workspace_id', $ws->id)->get()->keyBy('name');
        $this->assertSame(['hourly', '120.00'], [$rooms['Hourly']->pricing_model, $rooms['Hourly']->price_per_hour]);
        $this->assertSame('Bright room', $rooms['Hourly']->description);
        $this->assertSame('duration', $rooms['Duration']->pricing_model);
        $this->assertSame([60, 120], array_column($rooms['Duration']->pricing_rules['durations'], 'minutes'));
        $this->assertSame('people', $rooms['People']->pricing_model);
        $this->assertSame([2, 6], array_column($rooms['People']->pricing_rules['people'], 'max'));
        $this->assertSame('people_duration', $rooms['Matrix']->pricing_model);
        $this->assertEquals([[100, 500], [180, 900]], $rooms['Matrix']->pricing_rules['prices']);
    }

    public function test_conditional_pricing_validation_is_unchanged_for_each_model(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);
        $base = ['name' => 'Pod', 'type' => 'meeting', 'capacity' => 2];

        $this->create($owner, $ws, $base + ['pricing_model' => 'hourly'])->assertSessionHasErrors('price_per_hour');
        $this->create($owner, $ws, $base + ['pricing_model' => 'duration', 'pricing_rules' => json_encode(['durations' => [], 'prices' => []])])
            ->assertSessionHasErrors('pricing_rules');
        $this->create($owner, $ws, $base + ['pricing_model' => 'people', 'pricing_rules' => json_encode(['people' => [], 'prices' => []])])
            ->assertSessionHasErrors('pricing_rules');
        $this->create($owner, $ws, $base + ['pricing_model' => 'people_duration',
            'pricing_rules' => json_encode(['durations' => [['minutes' => 60]], 'people' => [['max' => 2]], 'prices' => [['']]])])
            ->assertSessionHasErrors('pricing_rules');
        $this->assertSame(0, Room::count());
    }

    // ------------------------------------------------------------------ optional extras

    public function test_pricing_profiles_and_plans_are_saved_and_may_stay_empty(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);

        $this->create($owner, $ws, ['name' => 'With extras', 'type' => 'meeting', 'capacity' => 10, 'pricing_model' => 'hourly', 'price_per_hour' => 100,
            'pricing_profiles' => json_encode([['name' => 'Photography', 'price_per_hour' => 700], ['name' => 'Film', 'price_per_hour' => 900, 'is_active' => false]]),
            'plans' => json_encode([['name' => 'Half day', 'people' => 8, 'minutes' => 240, 'price' => 600], ['name' => '', 'people' => 10, 'full_day' => true, 'price' => 1000]]),
        ])->assertSessionHasNoErrors();
        $this->create($owner, $ws, ['name' => 'Plain', 'type' => 'meeting', 'capacity' => 4, 'pricing_model' => 'hourly', 'price_per_hour' => 50,
            'pricing_profiles' => '[]', 'plans' => '[]'])->assertSessionHasNoErrors();

        $room = Room::where('name', 'With extras')->firstOrFail();
        $this->assertSame(['Photography', 'Film'], $room->pricingProfiles()->orderBy('sort_order')->pluck('name')->all());
        $this->assertFalse((bool) $room->pricingProfiles()->where('name', 'Film')->value('is_active'));
        $this->assertSame(['Half day', null], $room->plans()->orderBy('sort_order')->pluck('name')->all(), 'An unnamed plan is stored without a name, as before.');
        $this->assertTrue((bool) $room->plans()->where('people', 10)->value('is_full_day'));

        $plain = Room::where('name', 'Plain')->firstOrFail();
        $this->assertSame(0, $plain->pricingProfiles()->count() + $plain->plans()->count());
    }

    // ------------------------------------------------------------------ failed submissions keep the work

    public function test_a_failed_submission_reopens_the_step_with_the_error_and_keeps_every_value(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);
        $profiles = json_encode([['id' => null, 'name' => 'Photography', 'price_per_hour' => 700, 'is_active' => true]]);
        $plans = json_encode([['id' => null, 'name' => 'Team day', 'people' => 6, 'minutes' => null, 'full_day' => true, 'price' => 900]]);
        $all = ['name' => 'Board Room', 'type' => 'meeting', 'capacity' => 6, 'description' => 'Projector and whiteboard',
            'pricing_model' => 'people_duration', 'pricing_rules' => json_encode(self::MATRIX), 'pricing_profiles' => $profiles, 'plans' => $plans];

        // Step 1 problem (name too long): back on step 1, everything else intact.
        $page = $this->failAndReopen($owner, $ws, ['name' => str_repeat('x', 256)] + $all);
        $page->assertSee('data-start-step="1"', false)->assertSee('data-has-errors="1"', false)
            ->assertSee(__('app.workspace.wizard.fix_errors'))
            ->assertSee('aria-describedby="room_name-error"', false)->assertSee('aria-invalid="true"', false)
            ->assertSee('Projector and whiteboard')
            ->assertSee('value="people_duration" checked', false)
            ->assertSee('value="'.e(json_encode(self::MATRIX)).'"', false)
            ->assertSee('value="'.e($profiles).'"', false)
            ->assertSee('value="'.e($plans).'"', false);

        // Step 2 problem (a price missing): reopens pricing.
        $broken = json_encode(['durations' => [['minutes' => 60]], 'people' => [['max' => 2]], 'prices' => [['']]]);
        $this->failAndReopen($owner, $ws, ['pricing_rules' => $broken] + $all)
            ->assertSee('data-start-step="2"', false)
            ->assertSee(__('app.pricing.errors.price_missing'))
            ->assertSee('value="Board Room"', false);

        // Step 3 problem (a plan without a price): reopens extras, plans section open.
        $noPrice = json_encode([['name' => 'Team day', 'people' => 6, 'full_day' => true, 'price' => '']]);
        $html = $this->failAndReopen($owner, $ws, ['plans' => $noPrice] + $all)
            ->assertSee('data-start-step="3"', false)
            ->assertSee(__('app.plans.errors.price_missing'))
            ->getContent();
        $this->assertMatchesRegularExpression('/data-wizard-extra="plans"\s+open/', $html);

        $this->assertSame(0, Room::count(), 'Nothing is saved by a failed attempt.');
    }

    public function test_the_plan_limit_refusal_keeps_the_input_and_creates_nothing(): void
    {
        $owner = $this->owner(maxRooms: 1);
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);
        Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'First', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 90]);

        $this->create($owner, $ws, ['name' => 'Second', 'type' => 'meeting', 'capacity' => 4, 'pricing_model' => 'hourly', 'price_per_hour' => 90])
            ->assertRedirect(route('rooms.create', $ws))->assertSessionHas('error', __('app.plan_limit.rooms'));
        $this->actingAs($owner, 'owner')->get(route('rooms.create', $ws))->assertSee('value="Second"', false);
        $this->assertSame(1, Room::count());
    }

    // ------------------------------------------------------------------ access and the "another use" variant

    public function test_access_and_the_add_another_use_variant_are_unchanged(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'HQ']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Hall', 'type' => 'shared', 'capacity' => 20, 'price_per_hour' => 60]);
        $stranger = $this->owner();

        $this->actingAs($stranger, 'owner')->get(route('rooms.create', $ws))->assertNotFound();
        $this->actingAs($stranger, 'owner')->post(route('rooms.store', $ws), ['name' => 'X', 'type' => 'meeting', 'capacity' => 1, 'price_per_hour' => 1])->assertNotFound();
        auth('owner')->logout();
        $this->get(route('rooms.create', $ws))->assertRedirect(route('login'));

        $html = $this->actingAs($owner, 'owner')->get(route('rooms.uses.create', [$ws, $room]))->assertOk()
            ->assertSee(__('app.workspace.uses.add_title', ['room' => 'Hall']))
            ->assertSee(__('app.workspace.uses.add_shares_hint', ['room' => 'Hall']))
            ->assertSee(__('app.workspace.wizard.steps.pricing'))
            ->getContent();
        $this->assertStringContainsString('action="'.route('rooms.uses.store', [$ws, $room]).'"', $html);
        $this->assertStringContainsString('value="20"', $html, 'Capacity starts from the room.');

        $this->actingAs($owner, 'owner')->post(route('rooms.uses.store', [$ws, $room]), [
            'name' => 'Studio', 'type' => 'studio', 'capacity' => 8, 'pricing_model' => 'duration',
            'pricing_rules' => json_encode(['durations' => [['minutes' => 120]], 'prices' => [[300]]]),
        ])->assertRedirect(route('workspaces.index', ['workspace' => $ws->id]))->assertSessionHas('success');
        $this->assertSame($room->id, Room::where('name', 'Studio')->value('physical_room_id'));
    }
}
