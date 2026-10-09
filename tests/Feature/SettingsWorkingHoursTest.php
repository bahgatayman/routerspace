<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\WorkingHour;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Working Hours Phase 2: the Settings save action itself. Enforcement in
 * booking/session write paths is a later phase — this only covers
 * persisting the weekly schedule correctly (all-7-atomically, validation,
 * closed-day nulling) and gating the section by feature.
 */
class SettingsWorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function owner(array $features = ['workspace', 'booking']): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => $features,
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);

        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);

        foreach ($features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    /** A full valid 7-day payload: open Mon-Fri 09:00-17:00, closed Sat/Sun. */
    private function payload(array $overrides = []): array
    {
        $hours = [];
        foreach (range(0, 6) as $dow) {
            $isOpen = $dow >= 1 && $dow <= 5;
            $hours[$dow] = [
                'is_open' => $isOpen ? '1' : '0',
                'open_time' => $isOpen ? '09:00' : '',
                'close_time' => $isOpen ? '17:00' : '',
            ];
        }

        foreach ($overrides as $dow => $override) {
            $hours[$dow] = array_merge($hours[$dow], $override);
        }

        return ['hours' => $hours];
    }

    public function test_valid_save_persists_all_seven_days(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')
            ->post(route('settings.working-hours.update'), $this->payload())
            ->assertSessionHas('success');

        $this->assertSame(7, $owner->workingHours()->count());

        $monday = $owner->workingHours()->where('day_of_week', 1)->first();
        $this->assertTrue($monday->is_open);
        $this->assertSame('09:00', $monday->open_time);
        $this->assertSame('17:00', $monday->close_time);

        $saturday = $owner->workingHours()->where('day_of_week', 6)->first();
        $this->assertFalse($saturday->is_open);
        $this->assertNull($saturday->open_time);
        $this->assertNull($saturday->close_time);
    }

    public function test_resaving_replaces_rather_than_duplicates(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->post(route('settings.working-hours.update'), $this->payload());
        $this->actingAs($owner, 'owner')->post(route('settings.working-hours.update'), $this->payload([
            1 => ['open_time' => '10:00'],
        ]));

        $this->assertSame(7, $owner->workingHours()->count());
        $this->assertSame('10:00', $owner->workingHours()->where('day_of_week', 1)->first()->open_time);
    }

    public function test_open_day_missing_a_time_is_rejected_and_leaves_existing_rows_untouched(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner')->post(route('settings.working-hours.update'), $this->payload());

        $response = $this->actingAs($owner, 'owner')->post(route('settings.working-hours.update'), $this->payload([
            2 => ['open_time' => ''],
        ]));

        $response->assertSessionHas('error');
        // The previous, valid save is untouched — no partial overwrite.
        $tuesday = $owner->workingHours()->where('day_of_week', 2)->first();
        $this->assertSame('09:00', $tuesday->open_time);
    }

    public function test_equal_open_and_close_time_is_rejected(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'owner')->post(route('settings.working-hours.update'), $this->payload([
            1 => ['open_time' => '09:00', 'close_time' => '09:00'],
        ]));

        $response->assertSessionHas('error');
        $this->assertSame(0, $owner->workingHours()->count());
    }

    public function test_closing_a_previously_open_day_nulls_its_stored_times(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner')->post(route('settings.working-hours.update'), $this->payload());

        $this->actingAs($owner, 'owner')->post(route('settings.working-hours.update'), $this->payload([
            1 => ['is_open' => '0', 'open_time' => '09:00', 'close_time' => '17:00'],
        ]));

        $monday = $owner->workingHours()->where('day_of_week', 1)->first();
        $this->assertFalse($monday->is_open);
        $this->assertNull($monday->open_time);
        $this->assertNull($monday->close_time);
    }

    public function test_incomplete_day_submission_is_rejected(): void
    {
        $owner = $this->owner();

        $payload = $this->payload();
        unset($payload['hours'][6]);

        $response = $this->actingAs($owner, 'owner')->post(route('settings.working-hours.update'), $payload);

        $response->assertSessionHas('error');
        $this->assertSame(0, $owner->workingHours()->count());
    }

    public function test_owner_without_workspace_or_booking_feature_cannot_save(): void
    {
        $owner = $this->owner(['hotspot']);

        $this->actingAs($owner, 'owner')
            ->post(route('settings.working-hours.update'), $this->payload())
            ->assertRedirect('/dashboard');

        $this->assertSame(0, $owner->workingHours()->count());
    }

    public function test_settings_page_shows_working_hours_section_for_booking_owner(): void
    {
        $owner = $this->owner(['booking']);
        WorkingHour::create([
            'owner_id' => $owner->id, 'day_of_week' => 1,
            'is_open' => true, 'open_time' => '09:00:00', 'close_time' => '17:00:00',
        ]);

        // The page renders the working-hours card when workspace or booking is on.
        $this->actingAs($owner, 'owner')
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Settings/Index')
                ->where('features.booking', true)
                ->has('workingHours', 7)
                ->where('hasConfiguredWorkingHours', true)
                ->where('workingHours.2.day_of_week', 1)
                ->where('workingHours.2.is_open', true)
                ->where('workingHours.2.open_time', '09:00')
                ->where('workingHours.2.close_time', '17:00'));
    }

    public function test_settings_page_hides_working_hours_section_for_hotspot_only_owner(): void
    {
        $owner = $this->owner(['hotspot']);

        // The working-hours card is only rendered when workspace or booking is on.
        $this->actingAs($owner, 'owner')
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Settings/Index')
                ->where('features.hotspot', true)
                ->where('features.workspace', false)
                ->where('features.booking', false));
    }

    /**
     * Regression test: lang/ar/app.php was missing the whole 'section' array
     * (used for the page's own <h1>), and day names were built from Carbon's
     * own format('l'), which is always English regardless of app locale —
     * both left an Arabic-locale page with English text mixed in.
     */
    public function test_settings_page_is_fully_translated_in_arabic(): void
    {
        $owner = $this->owner();

        $response = $this->withSession(['locale' => 'ar'])
            ->actingAs($owner, 'owner')
            ->get('/settings');

        $response->assertOk();
        // Page strings come from the Arabic lang table (window.LS_LANG, JSON-escaped); the h1 is t('section.settings'),
        // so the Arabic 'section' array must exist.
        $this->assertNotSame('app.section.settings', __('app.section.settings', [], 'ar'));
        $this->assertNotSame('Settings', __('app.section.settings', [], 'ar'));
        $response->assertInertia(fn (Assert $p) => $p->component('Settings/Index')
            ->where('locale', 'ar')
            ->where('workingHours.0.day_name', __('app.day.saturday', [], 'ar'))
            ->where('workingHours.1.day_name', __('app.day.sunday', [], 'ar')));
        $props = json_encode($response->inertiaProps(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Settings', $props);
        $this->assertStringNotContainsString('Saturday', $props);
    }
}
