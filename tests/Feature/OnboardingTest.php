<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Workspace;
use App\Services\OnboardingService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hybrid onboarding: first-visit Welcome Wizard + persistent dashboard checklist.
 * Completion is always derived from real data; state is per owner (tenant).
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function owner(array $features = ['workspace', 'booking', 'sales'], bool $new = true): Owner
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => $features, 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Mona Adel', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Mona Adel',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        foreach ($features as $key) {
            $owner->enableFeature($key);
        }
        if (! $new) {
            $owner->forceFill(['onboarding_welcomed_at' => now()->subMonth(), 'onboarding_checklist_hidden_at' => now()])->save();
        }

        return $owner->fresh();
    }

    private function as($user, string $guard): static
    {
        auth('owner')->logout();
        auth('staff')->logout();

        return $this->actingAs($user, $guard);
    }

    private function room(Owner $owner, array $attrs = []): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create(array_merge(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room A',
            'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100, 'is_available' => true], $attrs));
    }

    private function tasks(Owner $owner): array
    {
        return collect((new OnboardingService)->tasks($owner->fresh()))->keyBy('key')->map(fn ($t) => $t['done'])->all();
    }

    // ------------------------------------------------------------------ wizard lifecycle

    public function test_a_new_owner_sees_the_welcome_wizard_and_checklist_on_first_dashboard_visit(): void
    {
        $owner = $this->owner();

        $this->as($owner, 'owner')->get('/dashboard')->assertOk()
            ->assertSee('id="welcome-wizard"', false)
            ->assertSee('id="onboarding-checklist"', false)
            ->assertSee(__('app.onboarding.welcome_title', ['name' => 'Mona']));
    }

    public function test_once_dismissed_the_wizard_never_reopens_by_itself_across_sessions(): void
    {
        $owner = $this->owner();

        $this->as($owner, 'owner')->postJson('/onboarding/welcome/dismiss')->assertOk()->assertJson(['ok' => true, 'next' => '/dashboard']);
        $this->assertNotNull($owner->fresh()->onboarding_welcomed_at);

        // A brand-new session (fresh login) and a refresh: still no wizard, checklist still there.
        $this->as($owner->fresh(), 'owner')->get('/dashboard')->assertOk()
            ->assertDontSee('id="welcome-wizard"', false)
            ->assertSee('id="onboarding-checklist"', false);
        $this->get('/dashboard')->assertDontSee('id="welcome-wizard"', false);

        // Dismissing twice keeps the first timestamp.
        $first = $owner->fresh()->onboarding_welcomed_at;
        $this->travel(5)->minutes();
        $this->postJson('/onboarding/welcome/dismiss')->assertOk();
        $this->assertTrue($first->equalTo($owner->fresh()->onboarding_welcomed_at));
    }

    public function test_replay_is_an_explicit_action_that_shows_the_wizard_again(): void
    {
        $owner = $this->owner(new: false);

        $this->as($owner, 'owner')->get('/dashboard')->assertDontSee('id="welcome-wizard"', false);
        $this->post('/onboarding/welcome/restart')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertSee('id="welcome-wizard"', false);
    }

    public function test_dismiss_only_redirects_to_the_owners_own_task_links(): void
    {
        $owner = $this->owner();
        $this->as($owner, 'owner');

        $this->post('/onboarding/welcome/dismiss', ['next' => 'https://evil.example/phish'])->assertRedirect('/dashboard');
        $owner->forceFill(['onboarding_welcomed_at' => null])->save();
        $this->post('/onboarding/welcome/dismiss', ['next' => '/workspaces/create'])->assertRedirect('/workspaces/create');
    }

    public function test_existing_customers_are_back_filled_as_already_welcomed_with_the_checklist_hidden(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['onboarding_welcomed_at' => null, 'onboarding_checklist_hidden_at' => null])->save();

        $migration = require database_path('migrations/2026_10_18_000001_add_onboarding_state_to_owners_table.php');
        $migration->down();
        $migration->up();

        $owner = $owner->fresh();
        $this->assertNotNull($owner->onboarding_welcomed_at);
        $this->assertNotNull($owner->onboarding_checklist_hidden_at);
        $this->as($owner, 'owner')->get('/dashboard')->assertOk()
            ->assertDontSee('id="welcome-wizard"', false)
            ->assertDontSee('id="onboarding-checklist"', false)
            ->assertSee(__('app.onboarding.checklist_show'));
    }

    public function test_a_fresh_signup_lands_on_the_dashboard_with_the_wizard(): void
    {
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_members' => 10, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);

        $this->followingRedirects()->post('/register', ['name' => 'Omar Ali', 'email' => 'omar@t.local', 'password' => 'secret1234'])
            ->assertOk()->assertSee('id="welcome-wizard"', false);
    }

    // ------------------------------------------------------------------ checklist

    public function test_hiding_the_checklist_keeps_progress_and_it_can_be_reopened(): void
    {
        $owner = $this->owner();
        $this->room($owner);
        $before = $this->tasks($owner);

        $this->as($owner, 'owner')->postJson('/onboarding/checklist/hide')->assertOk();
        $this->get('/dashboard')->assertDontSee('id="onboarding-checklist"', false)
            ->assertSee(__('app.onboarding.checklist_show_progress', ['done' => 3, 'total' => 6]));
        $this->assertSame($before, $this->tasks($owner));

        $this->post('/onboarding/checklist/show')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertSee('id="onboarding-checklist"', false);
    }

    public function test_completion_follows_real_data_created_anywhere_in_the_app(): void
    {
        $owner = $this->owner();
        $this->assertSame(['location' => false, 'rooms' => false, 'pricing' => false, 'products' => false, 'staff' => false, 'first_booking' => false], $this->tasks($owner));

        // A free (0-priced) hourly room counts as a room, not as pricing.
        $room = $this->room($owner, ['price_per_hour' => 0]);
        $this->assertSame(['location' => true, 'rooms' => true, 'pricing' => false], array_slice($this->tasks($owner), 0, 3));

        $room->update(['price_per_hour' => 60]);
        Product::create(['owner_id' => $owner->id, 'name' => 'Tea', 'type' => 'product', 'price' => 10, 'is_active' => true]);
        Staff::create(['owner_id' => $owner->id, 'role_id' => Role::whereNull('owner_id')->where('key', 'manager')->value('id'),
            'name' => 'Sara', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        $member = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Guest', 'phone' => '01000000001', 'password' => 'x']);
        Booking::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id, 'booking_date' => today(),
            'start_time' => '10:00', 'end_time' => '11:00', 'price_per_hour' => 60, 'total_hours' => 1, 'total_price' => 60, 'status' => 'confirmed']);

        $this->assertSame(['location' => true, 'rooms' => true, 'pricing' => true, 'products' => true, 'staff' => true, 'first_booking' => true], $this->tasks($owner));

        $summary = (new OnboardingService)->summary($owner->fresh());
        $this->assertTrue($summary['all_complete']);
        $this->as($owner, 'owner')->get('/dashboard')->assertSee(__('app.onboarding.complete_title'));
    }

    public function test_rule_based_pricing_counts_as_priced_and_unavailable_rooms_do_not(): void
    {
        $owner = $this->owner(['workspace']);
        $this->room($owner, ['price_per_hour' => 80, 'is_available' => false]);
        $this->assertFalse($this->tasks($owner)['pricing']);

        $this->room($owner, ['name' => 'Tiered', 'price_per_hour' => 0, 'pricing_model' => 'duration',
            'pricing_rules' => ['tiers' => [['minutes' => 60, 'price' => 50]]]]);
        $this->assertTrue($this->tasks($owner)['pricing']);
    }

    public function test_enabled_modules_decide_which_tasks_exist(): void
    {
        $this->assertSame(['location', 'rooms', 'pricing', 'staff'], array_keys($this->tasks($this->owner(['workspace']))));
        $this->assertSame(['router', 'staff', 'first_member'], array_keys($this->tasks($this->owner(['hotspot']))));
        $this->assertSame(['location', 'rooms', 'pricing', 'router', 'products', 'staff', 'first_booking'],
            array_keys($this->tasks($this->owner(['hotspot', 'workspace', 'booking', 'sales']))));
    }

    public function test_optional_tasks_never_block_the_essential_ready_state(): void
    {
        $owner = $this->owner();
        $this->room($owner);

        $summary = (new OnboardingService)->summary($owner->fresh());
        $this->assertTrue($summary['essential_complete']);
        $this->assertFalse($summary['all_complete']);
        $this->assertSame('first_booking', $summary['next']['key']);
    }

    public function test_hotspot_router_task_completes_only_with_real_router_details(): void
    {
        $owner = $this->owner(['hotspot']);
        $this->assertFalse($this->tasks($owner)['router']);

        $owner->forceFill(['mikrotik_host' => '10.0.0.1', 'mikrotik_username' => 'api'])->save();
        $this->assertTrue($this->tasks($owner)['router']);
    }

    // ------------------------------------------------------------------ wizard profile step

    public function test_the_space_name_step_is_validated_and_saved_for_the_signed_in_owner_only(): void
    {
        $owner = $this->owner();
        $other = $this->owner();

        $this->as($owner, 'owner')->postJson('/onboarding/profile', ['business_name' => ''])->assertStatus(422)->assertJsonValidationErrors('business_name');
        $this->postJson('/onboarding/profile', ['business_name' => '  Hub Space  ', 'owner_id' => $other->id])->assertOk();

        $this->assertSame('Hub Space', $owner->fresh()->business_name);
        $this->assertSame('Mona Adel', $other->fresh()->business_name);
    }

    // ------------------------------------------------------------------ access + isolation

    public function test_staff_and_guests_never_get_onboarding(): void
    {
        $owner = $this->owner();
        $staff = Staff::create(['owner_id' => $owner->id, 'role_id' => Role::whereNull('owner_id')->where('key', 'manager')->value('id'),
            'name' => 'Sara', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);

        $this->as($staff, 'staff')->get('/dashboard')->assertOk()
            ->assertDontSee('id="welcome-wizard"', false)->assertDontSee('id="onboarding-checklist"', false);
        foreach (['/onboarding/welcome/dismiss', '/onboarding/welcome/restart', '/onboarding/profile', '/onboarding/checklist/hide', '/onboarding/checklist/show'] as $url) {
            $this->post($url, ['business_name' => 'Hijack'])->assertRedirect();
        }
        $this->assertNull($owner->fresh()->onboarding_welcomed_at);
        $this->assertSame('Mona Adel', $owner->fresh()->business_name);

        auth('staff')->logout();
        $this->post('/onboarding/welcome/dismiss')->assertRedirect(route('login'));
    }

    public function test_another_business_data_never_counts_toward_progress(): void
    {
        $alpha = $this->owner();
        $bravo = $this->owner();
        $this->room($bravo);
        Product::create(['owner_id' => $bravo->id, 'name' => 'Tea', 'type' => 'product', 'price' => 10, 'is_active' => true]);

        $this->assertSame([false, false, false, false], array_values(array_intersect_key($this->tasks($alpha), array_flip(['location', 'rooms', 'pricing', 'products']))));
    }

    public function test_an_owner_who_ignores_onboarding_can_use_the_app_normally(): void
    {
        $owner = $this->owner();

        $this->as($owner, 'owner');
        foreach (['/workspaces', '/workspaces/create', '/bookings', '/products', '/staff'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertNull($owner->fresh()->onboarding_welcomed_at);
    }

    public function test_bookings_first_use_empty_state_points_to_real_workflows(): void
    {
        $owner = $this->owner();

        $this->as($owner, 'owner')->get('/bookings')->assertOk()
            ->assertSee(__('app.onboarding.empty.bookings_title'))
            ->assertSee('href="/bookings/create"', false)
            ->assertSee('href="/active-sessions"', false);
        $this->get('/bookings?status=confirmed')->assertOk()->assertSee(__('app.empty.no_bookings'));
    }
}
