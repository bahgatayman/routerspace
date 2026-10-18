<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sign-up page: name + email + password only, trial note, inline errors, friendly duplicate-email message. */
class SignupPageUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function freePlan(): Plan
    {
        return Plan::create(['name' => 'Free', 'slug' => 'free-'.uniqid(), 'max_members' => 10, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
    }

    public function test_page_is_name_email_password_only_and_mentions_the_free_month_when_available(): void
    {
        $this->get('/register')->assertOk()->assertDontSee(__('app.auth.signup.trial'));

        $this->freePlan();
        $html = $this->get('/register')->assertOk()
            ->assertSee(__('app.auth.signup.trial'))
            ->assertSee('autocomplete="new-password"', false)
            ->getContent();

        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringNotContainsString('name="business_name"', $html);
        $this->assertStringNotContainsString('name="password_confirmation"', $html);
    }

    public function test_validation_errors_show_next_to_their_fields(): void
    {
        $this->from('/register')->post('/register', ['name' => '', 'email' => 'not-an-email', 'password' => 'short'])
            ->assertRedirect('/register');

        $html = $this->get('/register')->getContent();
        $this->assertStringContainsString('id="name-msg"', $html);
        $this->assertMatchesRegularExpression('/id="email"[^>]*class="field is-invalid"/', $html);
        $this->assertMatchesRegularExpression('/<p class="field-msg" id="password-msg">/', $html);
        $this->assertSame(0, Owner::count());
    }

    public function test_an_existing_email_gets_a_friendly_message_with_a_sign_in_link(): void
    {
        $this->freePlan();
        Owner::create(['name' => 'A', 'email' => 'taken@t.local', 'password' => 'secret123', 'business_name' => 'A']);

        $payload = ['name' => 'B', 'email' => 'taken@t.local', 'password' => 'secret1234'];

        $this->from('/register')->post('/register', $payload)
            ->assertRedirect('/register')->assertSessionHasErrors(['email' => __('app.auth.signup.email_taken')]);

        // What the person sees after the redirect back to the form.
        $this->from('/register')->followingRedirects()->post('/register', $payload)
            ->assertSee(__('app.auth.signup.email_taken'))
            ->assertSee(__('app.auth.signup.sign_in_instead'));
        $this->assertSame(1, Owner::count());
    }

    public function test_signup_without_business_name_uses_the_owners_name_and_lands_on_the_dashboard(): void
    {
        $this->freePlan();

        $this->post('/register', ['name' => 'Mona Adel', 'email' => 'new@t.local', 'password' => 'secret1234'])
            ->assertRedirect('/dashboard');

        $owner = Owner::where('email', 'new@t.local')->firstOrFail();
        $this->assertSame('Mona Adel', $owner->business_name);
        $this->assertAuthenticatedAs($owner, 'owner');
    }

    public function test_a_business_name_is_still_accepted_when_sent(): void
    {
        $this->freePlan();

        $this->post('/register', ['name' => 'Omar', 'business_name' => 'Hub Space', 'email' => 'omar@t.local', 'password' => 'secret1234'])
            ->assertRedirect('/dashboard');

        $this->assertSame('Hub Space', Owner::where('email', 'omar@t.local')->value('business_name'));
    }
}
