<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use App\Models\WorkspaceImage;
use App\Services\PublicWorkspaceDirectory;
use App\Services\WorkspacePublicationService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * /api/v1 public workspace directory: eligibility (status + live readiness),
 * strict field allow-list, slug lookup / 404s, pagination & validation,
 * N+1 safety, rate limiting, CORS, locale, proxy-aware URLs, and isolation
 * from the owner/admin Panel.
 */
class PublicWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    private const LIST_KEYS = ['slug', 'name', 'city', 'address', 'coordinates', 'description', 'primary_image', 'rooms_count', 'starting_price'];

    private const DETAIL_KEYS = ['slug', 'name', 'city', 'address', 'coordinates', 'description', 'primary_image', 'rooms_count', 'starting_price', 'images', 'rooms'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        RateLimiter::clear('public-api|127.0.0.1');
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function owner(string $name = 'Karim'): Owner
    {
        $plan = Plan::create(['name' => 'Free', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => $name, 'email' => strtolower($name).uniqid().'@private.test', 'password' => 'secret123',
            'business_name' => $name.' Biz', 'phone' => '01099998888', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('workspace');

        return $owner;
    }

    /** Ready + published (status set directly; the workflow itself is covered by WorkspacePublicationTest). */
    private function publicWorkspace(?Owner $owner = null, string $name = 'Cairo Desk', string $status = Workspace::PUBLISHED): Workspace
    {
        $owner ??= $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => $name, 'address' => '10 Tahrir St', 'city' => 'Cairo',
            'description' => 'Quiet coworking with fast Wi-Fi.', 'phone' => '01012345678', 'latitude' => 30.04, 'longitude' => 31.23]);
        WorkspaceImage::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'path' => "workspace-images/{$ws->id}/main.jpg",
            'thumbnail_path' => "workspace-images/{$ws->id}/main-thumb.webp", 'is_primary' => true, 'sort_order' => 1]);
        WorkspaceImage::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'path' => "workspace-images/{$ws->id}/second.jpg",
            'is_primary' => false, 'sort_order' => 2]);
        Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Board Room', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 150]);
        Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Hot Desk', 'type' => 'shared', 'capacity' => 20, 'price_per_hour' => 40]);
        $ws->forceFill(['publication_status' => $status, 'slug' => Str::slug($name).'-'.$ws->id, 'published_at' => now()])->save();

        return $ws->fresh();
    }

    // ---------------------------------------------------------------- eligibility

    public function test_only_published_and_currently_ready_workspaces_are_listed(): void
    {
        $owner = $this->owner();
        $live = $this->publicWorkspace($owner, 'Live Hub');
        foreach ([Workspace::DRAFT, Workspace::PENDING_REVIEW, Workspace::REJECTED, Workspace::UNPUBLISHED] as $status) {
            $this->publicWorkspace($owner, 'Hidden '.$status, $status);
        }
        $notReady = $this->publicWorkspace($owner, 'Published But Broken');
        $notReady->forceFill(['description' => ''])->save();

        $this->getJson('/api/v1/workspaces')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $live->slug)
            ->assertJsonPath('meta.total', 1);

        foreach (Workspace::where('id', '!=', $live->id)->get() as $hidden) {
            $this->getJson('/api/v1/workspaces/'.$hidden->slug)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        }
        $this->getJson('/api/v1/workspaces/'.$live->slug)->assertOk();
    }

    public function test_each_readiness_requirement_hides_a_published_workspace_until_fixed(): void
    {
        $owner = $this->owner();
        $ws = $this->publicWorkspace($owner);
        $rooms = $ws->rooms;

        $cases = [
            'name' => [fn () => $ws->forceFill(['name' => ' '])->save(), fn () => $ws->forceFill(['name' => 'Cairo Desk'])->save()],
            'location' => [fn () => $ws->forceFill(['address' => null])->save(), fn () => $ws->forceFill(['address' => '10 Tahrir St'])->save()],
            'description' => [fn () => $ws->forceFill(['description' => null])->save(), fn () => $ws->forceFill(['description' => 'Back'])->save()],
            'primary_image' => [fn () => $ws->images()->update(['is_primary' => false]), fn () => $ws->images()->where('sort_order', 1)->update(['is_primary' => true])],
            'priced_room' => [fn () => $ws->rooms()->update(['price_per_hour' => 0]), fn () => $ws->rooms()->update(['price_per_hour' => 90])],
            'room_available' => [fn () => $ws->rooms()->update(['is_available' => false]), fn () => $ws->rooms()->update(['is_available' => true])],
            'owner_active' => [fn () => $owner->forceFill(['is_active' => false])->save(), fn () => $owner->forceFill(['is_active' => true])->save()],
            'subscription' => [fn () => $owner->forceFill(['subscription_expires_at' => now()->subMinute()])->save(), fn () => $owner->forceFill(['subscription_expires_at' => now()->addMonth()])->save()],
        ];

        foreach ($cases as $key => [$break, $restore]) {
            $break();
            $this->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/v1/workspaces/'.$ws->slug)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            $restore();
            $this->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'data');
            $this->assertSame(Workspace::PUBLISHED, $ws->fresh()->publication_status, "{$key}: status untouched");
        }
        $this->assertCount(2, $rooms);
    }

    public function test_an_approved_workspace_disappears_after_unpublishing(): void
    {
        $owner = $this->owner();
        $ws = $this->publicWorkspace($owner, 'Nile Hub', Workspace::PENDING_REVIEW);
        $admin = Admin::create(['name' => 'Rev', 'email' => 'r@t.local', 'password' => bcrypt('x12345678')]);
        $this->actingAs($admin, 'admin');
        app(WorkspacePublicationService::class)->approve($ws, $admin);
        $this->getJson('/api/v1/workspaces/'.$ws->slug)->assertOk();

        app(WorkspacePublicationService::class)->unpublish($ws->fresh(), $admin, 'Temporarily closed for renovation');
        $this->getJson('/api/v1/workspaces/'.$ws->slug)->assertNotFound();
        $this->getJson('/api/v1/workspaces')->assertJsonCount(0, 'data');
    }

    // ---------------------------------------------------------------- fields

    public function test_list_and_detail_expose_only_allow_listed_fields(): void
    {
        $owner = $this->owner();
        $ws = $this->publicWorkspace($owner);
        $ws->publicationReviews()->create(['owner_id' => $owner->id, 'action' => 'rejected', 'from_status' => 'pending_review',
            'to_status' => 'rejected', 'actor_type' => 'admin', 'actor_name' => 'Secret Reviewer', 'reason' => 'INTERNAL-MODERATION-NOTE']);

        $list = $this->getJson('/api/v1/workspaces')->assertOk();
        $this->assertSame(self::LIST_KEYS, array_keys($list->json('data.0')));
        $this->assertSame(['url', 'thumbnail_url', 'is_primary'], array_keys($list->json('data.0.primary_image')));
        $this->assertSame(['amount', 'currency'], array_keys($list->json('data.0.starting_price')));

        $detail = $this->getJson('/api/v1/workspaces/'.$ws->slug)->assertOk();
        $this->assertSame(self::DETAIL_KEYS, array_keys($detail->json('data')));
        $this->assertSame(['id', 'name', 'type', 'type_label', 'capacity', 'price'], array_keys($detail->json('data.rooms.0')));
        // The room id is the only identifier exposed: no workspace, image or owner ids.
        $this->assertArrayNotHasKey('id', $detail->json('data'));
        $this->assertArrayNotHasKey('id', $detail->json('data.images.0'));
        $this->assertArrayNotHasKey('id', $detail->json('data.primary_image'));
        $this->assertArrayNotHasKey('id', $list->json('data.0'));
        $this->assertSame(['amount', 'currency', 'is_starting_price', 'display'], array_keys($detail->json('data.rooms.0.price')));

        foreach ([$list->getContent(), $detail->getContent()] as $json) {
            foreach ([$owner->email, $owner->business_name, '01099998888', '01012345678', '"owner', 'workspace_id', 'place_id', 'password',
                'publication_status', 'published_at', 'INTERNAL-MODERATION-NOTE', 'Secret Reviewer', 'is_active', 'is_available',
                'mikrotik', '"path":"workspace', 'thumbnail_path', 'sort_order', 'pricing_rules', 'billing'] as $secret) {
                $this->assertStringNotContainsString($secret, $json, "Leaked: {$secret}");
            }
            // Storage keys only ever appear inside full public URLs.
            $this->assertDoesNotMatchRegularExpression('#"workspace-images/#', $json);
        }
    }

    public function test_detail_returns_gallery_rooms_and_prices_from_the_pricing_service(): void
    {
        $ws = $this->publicWorkspace();
        $room = $ws->rooms()->where('name', 'Board Room')->first();
        $room->forceFill(['price_per_hour' => 0, 'pricing_model' => 'duration', 'pricing_rules' => ['durations' => [60, 120], 'prices' => [[90, 160]]]])->save();
        Room::create(['owner_id' => $ws->owner_id, 'workspace_id' => $ws->id, 'name' => 'Closed Room', 'type' => 'office', 'capacity' => 2, 'price_per_hour' => 70, 'is_available' => false]);
        Room::create(['owner_id' => $ws->owner_id, 'workspace_id' => $ws->id, 'name' => 'Free Room', 'type' => 'office', 'capacity' => 2, 'price_per_hour' => 0]);

        $res = $this->getJson('/api/v1/workspaces/'.$ws->slug)->assertOk()
            ->assertJsonPath('data.name', 'Cairo Desk')
            ->assertJsonPath('data.city', 'Cairo')
            ->assertJsonPath('data.rooms_count', 2)
            ->assertJsonPath('data.starting_price', ['amount' => 40, 'currency' => 'EGP'])
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.images.0.is_primary', true)
            ->assertJsonPath('data.images.0.url', asset("storage/workspace-images/{$ws->id}/main.jpg"))
            ->assertJsonPath('data.images.0.thumbnail_url', asset("storage/workspace-images/{$ws->id}/main-thumb.webp"))
            ->assertJsonPath('data.images.1.thumbnail_url', asset("storage/workspace-images/{$ws->id}/second.jpg"));

        $rooms = collect($res->json('data.rooms'))->keyBy('name');
        $this->assertSame(['Board Room', 'Hot Desk'], $rooms->keys()->all(), 'Unavailable and zero-priced rooms are excluded.');
        $this->assertEquals(['amount' => 90, 'currency' => 'EGP', 'is_starting_price' => true, 'display' => $room->fresh()->pricingSummary()], $rooms['Board Room']['price']);
        $this->assertEquals(['amount' => 40, 'currency' => 'EGP', 'is_starting_price' => false, 'display' => $ws->rooms()->where('name', 'Hot Desk')->first()->pricingSummary()], $rooms['Hot Desk']['price']);
        $this->assertSame('Meeting Room', $rooms['Board Room']['type_label']);
    }

    public function test_responses_follow_the_requested_language(): void
    {
        $ws = $this->publicWorkspace();

        $this->getJson('/api/v1/workspaces/'.$ws->slug.'?lang=ar')->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.rooms.0.type_label', __('app.room_type.meeting', [], 'ar'))
            ->assertJsonPath('data.rooms.0.price.display', '150.00 ج.م'.__('app.common.slash_hr', [], 'ar'));

        $this->getJson('/api/v1/workspaces/'.$ws->slug, ['Accept-Language' => 'ar-EG,ar;q=0.9'])->assertOk()->assertHeader('Content-Language', 'ar');
        $this->getJson('/api/v1/workspaces/'.$ws->slug.'?lang=fr')->assertOk()->assertHeader('Content-Language', 'en');
        $this->getJson('/api/v1/workspaces/missing?lang=ar')->assertNotFound()->assertExactJson(['message' => 'غير موجود.']);
    }

    // ---------------------------------------------------------------- slugs & 404

    public function test_slug_lookup_is_stable_and_not_found_never_explains_why(): void
    {
        $owner = $this->owner();
        $ws = $this->publicWorkspace($owner);
        $slug = $ws->slug;

        $this->actingAs($owner, 'owner')->put(route('workspaces.update', $ws), [
            'name' => 'Renamed Desk', 'address' => '10 Tahrir St', 'city' => 'Cairo', 'phone' => '01012345678', 'description' => 'Same place, new name.',
        ])->assertRedirect();
        auth('owner')->logout();

        $this->getJson('/api/v1/workspaces/'.$slug)->assertOk()->assertJsonPath('data.name', 'Renamed Desk')->assertJsonPath('data.slug', $slug);

        $draft = $this->publicWorkspace($owner, 'Draft Desk', Workspace::DRAFT);
        $notFound = ['message' => 'Not found.'];
        foreach (['does-not-exist', $draft->slug, 'UPPER-case', '..%2F..%2Fetc', 'a--b', str_repeat('a', 141), '-leading', (string) $ws->id] as $probe) {
            $this->getJson('/api/v1/workspaces/'.$probe)->assertNotFound()->assertExactJson($notFound);
        }
    }

    // ---------------------------------------------------------------- pagination & validation

    public function test_pagination_and_invalid_parameters(): void
    {
        $owner = $this->owner();
        for ($i = 1; $i <= 15; $i++) {
            $this->publicWorkspace($owner, 'Space '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $this->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.total', 15)->assertJsonPath('meta.per_page', 12)->assertJsonPath('meta.last_page', 2);

        $page2 = $this->getJson('/api/v1/workspaces?per_page=5&page=2')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 3);
        $this->assertStringContainsString('per_page=5', (string) $page2->json('links.next'));

        $this->getJson('/api/v1/workspaces?page=9')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 15);

        foreach (['per_page=0', 'per_page=51', 'per_page=abc', 'page=0', 'page=-1', 'page=x', 'page=99999'] as $bad) {
            $res = $this->getJson('/api/v1/workspaces?'.$bad)->assertStatus(422)->assertJsonPath('message', 'The request is invalid.');
            $this->assertNotEmpty($res->json('errors'));
            $this->assertArrayNotHasKey('exception', $res->json());
            $this->assertArrayNotHasKey('trace', $res->json());
        }

        // Unknown parameters are ignored, not errors.
        $this->getJson('/api/v1/workspaces?owner_id=1&status=draft&include=owner')->assertOk()->assertJsonCount(12, 'data');
    }

    public function test_listing_does_not_run_queries_per_workspace(): void
    {
        $owner = $this->owner();
        $this->publicWorkspace($owner, 'One');
        $this->publicWorkspace($owner, 'Two');

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/workspaces?per_page=50')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $count();
        for ($i = 0; $i < 8; $i++) {
            $this->publicWorkspace($owner, 'More '.$i);
        }
        $this->assertSame($few, $count(), 'Query count must not grow with the number of workspaces.');
    }

    // ---------------------------------------------------------------- rate limit / CORS / proxy

    public function test_public_endpoints_are_rate_limited(): void
    {
        config(['api.public_rate_limit' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/workspaces')->assertOk()->assertHeader('X-RateLimit-Limit', '3');
        }
        $res = $this->getJson('/api/v1/workspaces/anything')->assertStatus(429)->assertExactJson(['message' => 'Too many requests. Please try again shortly.']);
        $this->assertNotNull($res->headers->get('Retry-After'));
    }

    public function test_cors_allows_only_configured_origins_without_credentials(): void
    {
        config(['cors.allowed_origins' => ['https://link-space.net']]);

        $this->getJson('/api/v1/workspaces', ['Origin' => 'https://link-space.net'])->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://link-space.net')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        // A disallowed origin is never echoed back (with one allowed origin the header names that origin, which browsers reject for others).
        $evil = $this->getJson('/api/v1/workspaces', ['Origin' => 'https://evil.example'])->assertOk();
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));

        $this->call('OPTIONS', '/api/v1/workspaces', [], [], [], [
            'HTTP_ORIGIN' => 'https://link-space.net', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://link-space.net');

        $evilPreflight = $this->call('OPTIONS', '/api/v1/workspaces', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNotSame('https://evil.example', $evilPreflight->headers->get('Access-Control-Allow-Origin'));

        // With nothing configured, no origin is allowed (never a wildcard).
        config(['cors.allowed_origins' => []]);
        $this->getJson('/api/v1/workspaces', ['Origin' => 'https://link-space.net'])->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_image_urls_honour_https_only_from_trusted_proxies(): void
    {
        $ws = $this->publicWorkspace();
        $forwarded = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'panel.example.test', 'X-Forwarded-Port' => '443'];

        // Untrusted (default): forwarded headers are ignored.
        $url = $this->getJson('/api/v1/workspaces/'.$ws->slug, $forwarded)->json('data.primary_image.url');
        $this->assertStringStartsWith('http://localhost/storage/', $url);

        // Trusted proxy (TRUSTED_PROXIES): URLs follow the original https request.
        TrustProxies::at('*');
        $url = $this->getJson('/api/v1/workspaces/'.$ws->slug, $forwarded)->json('data.primary_image.url');
        $this->assertSame('https://panel.example.test/storage/workspace-images/'.$ws->id.'/main.jpg', $url);
    }

    // ---------------------------------------------------------------- errors & isolation

    public function test_server_errors_never_expose_internals_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $this->mock(PublicWorkspaceDirectory::class)->shouldReceive('paginate')->andThrow(new \RuntimeException('SQLSTATE secret-table-name'));

        $res = $this->getJson('/api/v1/workspaces')->assertStatus(500)->assertExactJson(['message' => 'Something went wrong. Please try again later.']);
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
    }

    public function test_api_is_stateless_and_panel_routes_are_unchanged(): void
    {
        $this->publicWorkspace();

        $res = $this->getJson('/api/v1/workspaces')->assertOk();
        $this->assertEmpty($res->headers->getCookies(), 'The API sets no session or CSRF cookies.');

        // Only the two public endpoints exist under /api.
        $this->postJson('/api/v1/workspaces')->assertStatus(405)->assertExactJson(['message' => 'Method not allowed.']);
        foreach (['/api/user', '/api/v1/owners', '/api/v1/bookings', '/api/v1/admin/publications', '/api/workspaces'] as $path) {
            $this->getJson($path)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        }

        // Panel behaviour is untouched: guests are still redirected to the HTML login.
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/admin/dashboard')->assertRedirect('/login');
        $this->get('/workspaces')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    // ---------------------------------------------------------------- contract (Phase 3.1)

    public function test_coordinates_appear_only_for_a_valid_map_pin(): void
    {
        $ws = $this->publicWorkspace();
        $coords = fn () => $this->getJson('/api/v1/workspaces/'.$ws->slug)->assertOk()->json('data.coordinates');

        $this->assertSame(['latitude' => 30.04, 'longitude' => 31.23], $coords());
        $this->assertSame(['latitude' => 30.04, 'longitude' => 31.23], $this->getJson('/api/v1/workspaces')->json('data.0.coordinates'));

        $cases = [
            'no pin' => ['latitude' => null, 'longitude' => null],
            'half a pin' => ['latitude' => 30.04, 'longitude' => null],
            'null island' => ['latitude' => 0, 'longitude' => 0],
            'out of range' => ['latitude' => 95.5, 'longitude' => 31.23],
        ];
        foreach ($cases as $label => $values) {
            $ws->forceFill($values)->save();
            $this->assertNull($coords(), $label);
        }

        $ws->forceFill(['latitude' => -33.8688197, 'longitude' => 151.2092955])->save();
        $this->assertSame(['latitude' => -33.8688197, 'longitude' => 151.2092955], $coords());
    }

    public function test_room_ids_are_stable_and_only_for_public_rooms_of_that_workspace(): void
    {
        $owner = $this->owner();
        $ws = $this->publicWorkspace($owner, 'Mine');
        $other = $this->publicWorkspace($this->owner('Other'), 'Theirs');
        $closed = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Closed', 'type' => 'office', 'capacity' => 2, 'price_per_hour' => 60, 'is_available' => false]);

        $res = $this->getJson('/api/v1/workspaces/'.$ws->slug);
        $ids = collect($res->json('data.rooms'))->pluck('id', 'name');

        // The public id is the room's public_id UUID — never the internal primary key.
        $this->assertEquals($ws->rooms()->where('is_available', true)->pluck('public_id', 'name')->sortKeys(), $ids->sortKeys());
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }
        $this->assertDoesNotMatchRegularExpression('/"id":\s*\d/', $res->getContent(), 'No numeric id anywhere in the payload.');
        $this->assertNotContains($closed->public_id, $ids->all(), 'Unavailable rooms are not listed.');
        $this->assertEmpty(array_intersect($ids->all(), $other->rooms()->pluck('public_id')->all()), 'Only this workspace\'s rooms.');

        // Renaming a room keeps its public id.
        $board = $ws->rooms()->where('name', 'Board Room')->first();
        $board->forceFill(['name' => 'Boardroom XL'])->save();
        $this->assertSame($board->public_id, collect($this->getJson('/api/v1/workspaces/'.$ws->slug)->json('data.rooms'))->firstWhere('name', 'Boardroom XL')['id']);
    }

    public function test_exact_detail_contract(): void
    {
        $ws = $this->publicWorkspace(null, 'Contract Hub');
        $board = $ws->rooms()->where('name', 'Board Room')->first();
        $desk = $ws->rooms()->where('name', 'Hot Desk')->first();

        $this->getJson('/api/v1/workspaces/'.$ws->slug)->assertOk()->assertExactJson([
            'data' => [
                'slug' => $ws->slug,
                'name' => 'Contract Hub',
                'city' => 'Cairo',
                'address' => '10 Tahrir St',
                'coordinates' => ['latitude' => 30.04, 'longitude' => 31.23],
                'description' => 'Quiet coworking with fast Wi-Fi.',
                'primary_image' => [
                    'url' => asset("storage/workspace-images/{$ws->id}/main.jpg"),
                    'thumbnail_url' => asset("storage/workspace-images/{$ws->id}/main-thumb.webp"),
                    'is_primary' => true,
                ],
                'rooms_count' => 2,
                'starting_price' => ['amount' => 40, 'currency' => 'EGP'],
                'images' => [
                    ['url' => asset("storage/workspace-images/{$ws->id}/main.jpg"), 'thumbnail_url' => asset("storage/workspace-images/{$ws->id}/main-thumb.webp"), 'is_primary' => true],
                    ['url' => asset("storage/workspace-images/{$ws->id}/second.jpg"), 'thumbnail_url' => asset("storage/workspace-images/{$ws->id}/second.jpg"), 'is_primary' => false],
                ],
                'rooms' => [
                    ['id' => $board->public_id, 'name' => 'Board Room', 'type' => 'meeting', 'type_label' => 'Meeting Room', 'capacity' => 8,
                        'price' => ['amount' => 150, 'currency' => 'EGP', 'is_starting_price' => false, 'display' => 'EGP 150.00/ hr']],
                    ['id' => $desk->public_id, 'name' => 'Hot Desk', 'type' => 'shared', 'type_label' => 'Shared Space', 'capacity' => 20,
                        'price' => ['amount' => 40, 'currency' => 'EGP', 'is_starting_price' => false, 'display' => 'EGP 40.00/ hr']],
                ],
            ],
        ]);
    }

    public function test_listing_loads_only_what_the_cards_need(): void
    {
        $ws = $this->publicWorkspace();
        Room::create(['owner_id' => $ws->owner_id, 'workspace_id' => $ws->id, 'name' => 'Closed', 'type' => 'office', 'capacity' => 2, 'price_per_hour' => 60, 'is_available' => false]);

        $listed = app(PublicWorkspaceDirectory::class)->all()->first();
        $this->assertCount(1, $listed->images, 'Listing loads the primary photo only.');
        $this->assertTrue($listed->images->first()->is_primary);
        $this->assertCount(2, $listed->rooms, 'Only available, priced rooms are loaded.');
        $this->assertArrayNotHasKey('description', $listed->rooms->first()->getAttributes(), 'Long room descriptions are not loaded.');

        $detail = app(PublicWorkspaceDirectory::class)->findBySlug($ws->slug);
        $this->assertCount(2, $detail->images, 'Detail loads the full gallery.');
    }
}
