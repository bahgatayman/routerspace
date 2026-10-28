<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Query validation errors of the public API follow the API locale: Arabic is
 * translated (app.api.validation), English keeps Laravel's default text. The
 * envelope and status code are the same in both, and the Panel's global
 * validation messages are not affected.
 */
class PublicApiValidationLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('public-api|127.0.0.1');
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');
        parent::tearDown();
    }

    public function test_english_messages_are_laravel_defaults(): void
    {
        $this->getJson('/api/v1/workspaces?per_page=51&page=abc')->assertStatus(422)->assertExactJson([
            'message' => 'The request is invalid.',
            'errors' => [
                'page' => ['The page field must be an integer.'],
                'per_page' => ['The per page field must not be greater than 50.'],
            ],
        ]);
        $this->getJson('/api/v1/workspaces?lang=en&per_page=0')->assertStatus(422)
            ->assertExactJson(['message' => 'The request is invalid.', 'errors' => ['per_page' => ['The per page field must be at least 1.']]]);
    }

    public function test_arabic_messages_are_translated(): void
    {
        $this->getJson('/api/v1/workspaces?lang=ar&per_page=51&page=abc')->assertStatus(422)
            ->assertHeader('Content-Language', 'ar')
            ->assertExactJson([
                'message' => 'الطلب غير صالح.',
                'errors' => [
                    'page' => ['يجب أن يكون رقم الصفحة عددًا صحيحًا.'],
                    'per_page' => ['يجب ألا يزيد عدد النتائج في الصفحة عن 50.'],
                ],
            ]);
        $this->getJson('/api/v1/workspaces?lang=ar&page=0')->assertStatus(422)
            ->assertExactJson(['message' => 'الطلب غير صالح.', 'errors' => ['page' => ['يجب ألا يقل رقم الصفحة عن 1.']]]);
        $this->getJson('/api/v1/workspaces?lang=ar&page=10001')->assertStatus(422)
            ->assertJsonPath('errors.page.0', 'يجب ألا يزيد رقم الصفحة عن 10000.');
    }

    public function test_accept_language_header_selects_arabic(): void
    {
        $this->getJson('/api/v1/workspaces?per_page=0', ['Accept-Language' => 'ar'])->assertStatus(422)
            ->assertJsonPath('message', 'الطلب غير صالح.')
            ->assertJsonPath('errors.per_page.0', 'يجب ألا يقل عدد النتائج في الصفحة عن 1.');
    }

    public function test_valid_requests_and_panel_validation_are_unchanged(): void
    {
        $this->getJson('/api/v1/workspaces?lang=ar&per_page=5&page=1')->assertOk()->assertJsonPath('meta.per_page', 5);

        // The translations live under app.api.validation only — Laravel's global group is untouched.
        $this->assertSame('The page field must be an integer.', __('validation.integer', ['attribute' => 'page'], 'ar'));
    }
}
