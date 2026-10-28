<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Approved limits: 60 requests/minute per IP; 12 per page by default, 50 maximum. */
class PublicApiLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('public-api|127.0.0.1');
    }

    public function test_configured_defaults(): void
    {
        $this->assertSame(60, config('api.public_rate_limit'));
        $this->assertSame(12, config('api.per_page_default'));
        $this->assertSame(50, config('api.per_page_max'));
    }

    public function test_the_61st_request_in_a_minute_is_rejected(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->getJson('/api/v1/workspaces')->assertOk();
        }
        $last = $this->getJson('/api/v1/workspaces');
        $last->assertStatus(429)->assertExactJson(['message' => 'Too many requests. Please try again shortly.']);
        $this->assertNotNull($last->headers->get('Retry-After'));

        // Limits are per IP: another client is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->getJson('/api/v1/workspaces')->assertOk()->assertHeader('X-RateLimit-Limit', '60');
        RateLimiter::clear('public-api|10.0.0.9');
    }

    public function test_page_size_default_and_bounds(): void
    {
        $this->getJson('/api/v1/workspaces')->assertOk()->assertJsonPath('meta.per_page', 12);
        $this->getJson('/api/v1/workspaces?per_page=50')->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->getJson('/api/v1/workspaces?per_page=51')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/workspaces?per_page=0')->assertStatus(422)->assertJsonValidationErrors('per_page');
    }
}
