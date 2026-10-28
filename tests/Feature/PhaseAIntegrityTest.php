<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase A fixes not covered by their own files: API 401/403 JSON (Panel
 * redirects untouched), disabled rooms take no new bookings, and an Open
 * Session (no end time yet) no longer breaks the free/busy day view.
 */
class PhaseAIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): Owner
    {
        $plan = Plan::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, array $attrs = []): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create(array_merge(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room '.uniqid(), 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100], $attrs));
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    private function payload(Room $room, HotspotUser $user, array $overrides = []): array
    {
        return array_merge(['room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'booking_date' => today()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00'], $overrides);
    }

    // ---------------------------------------------------------- API auth errors

    private function registerProbeRoutes(): void
    {
        Route::middleware(['api', 'api.locale'])->prefix('api/v1/_probe')->group(function () {
            Route::get('/auth', fn () => 'secret')->middleware('auth:owner');
            Route::get('/forbidden', fn () => throw new AuthorizationException('No.'));
            Route::get('/abort401', fn () => abort(401));
        });
    }

    public function test_unauthenticated_api_requests_get_json_401_not_a_500_or_redirect(): void
    {
        $this->registerProbeRoutes();

        $this->getJson('/api/v1/_probe/auth')->assertStatus(401)->assertExactJson(['message' => 'Authentication is required.'])->assertHeaderMissing('Location');
        $this->get('/api/v1/_probe/auth')->assertStatus(401)->assertExactJson(['message' => 'Authentication is required.']);
        $this->getJson('/api/v1/_probe/auth?lang=ar')->assertStatus(401)->assertExactJson(['message' => 'يجب تسجيل الدخول.']);
        $this->getJson('/api/v1/_probe/abort401')->assertStatus(401)->assertExactJson(['message' => 'Authentication is required.']);
    }

    public function test_forbidden_api_requests_get_json_403(): void
    {
        $this->registerProbeRoutes();

        $this->getJson('/api/v1/_probe/forbidden')->assertStatus(403)->assertExactJson(['message' => 'You are not allowed to do this.']);
        $this->getJson('/api/v1/_probe/forbidden?lang=ar')->assertStatus(403)->assertExactJson(['message' => 'غير مسموح لك بتنفيذ هذا الإجراء.']);
    }

    public function test_panel_guests_are_still_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/admin/dashboard')->assertRedirect();
        $this->getJson('/dashboard')->assertRedirect('/login'); // non-API JSON requests: unchanged (redirect)
        $this->getJson('/api/v1/no-such-path?lang=ar')->assertStatus(404)->assertExactJson(['message' => 'غير موجود.']);
    }

    // ---------------------------------------------------------- disabled rooms

    public function test_a_disabled_room_takes_no_new_booking_from_the_form_or_the_modal(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, ['is_available' => false]);
        $user = $this->member($owner);

        $this->actingAs($owner, 'owner')->post('/bookings', $this->payload($room, $user))->assertSessionHas('error', __('app.booking.room_disabled'));
        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $user))
            ->assertStatus(422)->assertJson(['success' => false, 'message' => __('app.booking.room_disabled')]);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_disabled_room_takes_no_open_session(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, ['is_available' => false]);

        $this->actingAs($owner, 'owner')->postJson('/bookings', ['duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id])
            ->assertStatus(422)->assertJson(['message' => __('app.booking.room_disabled')]);

        $this->assertSame(0, Booking::count());
    }

    public function test_switching_a_room_back_on_makes_it_bookable_again(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, ['is_available' => false]);
        $room->update(['is_available' => true]);

        $this->actingAs($owner, 'owner')->postJson('/bookings', $this->payload($room, $this->member($owner)))->assertOk();
        $this->assertSame(1, Booking::count());
    }

    public function test_a_booking_cannot_be_moved_into_a_disabled_room(): void
    {
        $owner = $this->owner();
        $user = $this->member($owner);
        $open = $this->room($owner);
        $closed = $this->room($owner, ['is_available' => false]);
        $this->actingAs($owner, 'owner')->post('/bookings', $this->payload($open, $user));
        $booking = Booking::sole();

        $this->actingAs($owner, 'owner')->put("/bookings/{$booking->id}", $this->payload($closed, $user))
            ->assertSessionHas('error', __('app.booking.room_disabled'));

        $this->assertSame($open->id, $booking->fresh()->room_id);
    }

    public function test_a_booking_in_a_room_disabled_later_can_still_be_edited(): void
    {
        $owner = $this->owner();
        $user = $this->member($owner);
        $room = $this->room($owner);
        $this->actingAs($owner, 'owner')->post('/bookings', $this->payload($room, $user));
        $booking = Booking::sole();
        $room->update(['is_available' => false]);

        $this->actingAs($owner, 'owner')->put("/bookings/{$booking->id}", $this->payload($room, $user, ['end_time' => '13:00', 'notes' => 'Extended']))
            ->assertRedirect("/bookings/{$booking->id}")->assertSessionHas('success');

        // TIME reads back as '13:00' on SQLite, '13:00:00' on MySQL/MariaDB: compare HH:MM.
        $this->assertSame('13:00', substr((string) $booking->fresh()->end_time, 0, 5));
    }

    // ---------------------------------------------------------- open session in the day view

    public function test_an_open_session_occupies_the_rest_of_the_day_in_free_busy(): void
    {
        Carbon::setTestNow(Carbon::parse(today()->toDateString().' 14:00'));
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->actingAs($owner, 'owner')->postJson('/bookings', ['duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id])->assertOk();
        $this->assertNull(Booking::sole()->end_time);

        $blocks = app(AvailabilityService::class)->freeBusyForDay($room->fresh(), today()->toDateString());

        $busy = collect($blocks)->where('used', 1)->values();
        $this->assertCount(1, $busy);
        $this->assertSame('14:00', $busy[0]['start']);
        $this->assertSame(AvailabilityService::OPERATING_END, $busy[0]['end'], 'Occupied until the end of the displayed day.');
        $this->assertSame(0, $busy[0]['available']);
        $this->assertSame(1, collect($blocks)->firstWhere('end', '14:00')['available'], 'Free before it started.');

        // The screens that use it render instead of failing.
        $this->actingAs($owner, 'owner')->get('/bookings/calendar?view=day&date='.today()->toDateString())->assertOk();
        $this->assertSame([], collect(app(AvailabilityService::class)->bookableSlots($room->fresh(), today()->toDateString()))->where('start', '>=', '14:00')->where('available', true)->values()->all());
    }
}
