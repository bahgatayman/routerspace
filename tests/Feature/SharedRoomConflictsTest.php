<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Workspace;
use App\Services\AvailabilityService;
use App\Services\RoomPricingService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared-room conflicts that involve a session with no end time:
 *  1. a checked-in session running past its booked end (warned on Active
 *     Sessions, never auto-closed; once late it holds the room for the rest
 *     of the day, so nothing new can be booked into it);
 *  2. open walk-ins taking seats from later bookings of the same shared room
 *     (a walk-in holds its seats from its start to the end of that day; a
 *     checked-in party until its booked end, counted once by its headcount).
 * Concurrency of the walk-in read path on MySQL: RoomUsesConcurrencyTest.
 */
class SharedRoomConflictsTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    private Workspace $ws;

    private Room $shared;    // the physical room: "Shared Workspace", 6 seats

    private Room $training;  // another use: exclusive

    private Room $studio;    // another use: exclusive

    private string $today = '2026-10-05';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->at('08:00');

        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0,
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace', 'booking'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $this->owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($plan->features as $key) {
            $this->owner->enableFeature($key);
        }

        $this->ws = Workspace::create(['owner_id' => $this->owner->id, 'name' => 'Main']);
        $this->shared = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Shared Workspace',
            'type' => 'shared', 'capacity' => 6, 'price_per_hour' => 60]);
        $this->training = $this->use('Training Room', 'training', 400);
        $this->studio = $this->use('Studio', 'studio', 250);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function at(string $time, ?string $date = null): void
    {
        Carbon::setTestNow(Carbon::parse(($date ?? $this->today).' '.$time.':00'));
    }

    private function use(string $name, string $type, float $price): Room
    {
        $use = new Room(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => $name, 'type' => $type, 'capacity' => 10, 'price_per_hour' => $price]);
        $use->physical_room_id = $this->shared->id;
        $use->save();

        return $use;
    }

    private function member(): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $this->owner->id, 'name' => 'Member '.uniqid(), 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    private function postBooking(Room $room, string $start, string $end, int $party = 1, ?string $date = null)
    {
        return $this->actingAs($this->owner, 'owner')->post('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $this->member()->id, 'party_size' => $party,
            'booking_date' => $date ?? $this->today, 'start_time' => $start, 'end_time' => $end,
        ]);
    }

    /** A confirmed reservation of the shared room, checked in now: returns [booking, its open session]. */
    private function checkedIn(string $start, string $end, int $reserved = 1, int $seated = 1): array
    {
        $this->postBooking($this->shared, $start, $end, $reserved)->assertSessionHasNoErrors();
        $booking = Booking::where('room_id', $this->shared->id)->latest('id')->firstOrFail();
        $this->actingAs($this->owner, 'owner')->post("/bookings/{$booking->id}/check-in", ['party_size' => $seated])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');

        return [$booking->fresh(), SharedSession::where('booking_id', $booking->id)->where('status', 'open')->firstOrFail()];
    }

    private function walkIn(int $party, string $start = '08:00', ?Room $room = null): SharedSession
    {
        return SharedSession::create(['owner_id' => $this->owner->id, 'room_id' => ($room ?? $this->shared)->id, 'hotspot_user_id' => $this->member()->id,
            'party_size' => $party, 'session_date' => $this->today, 'start_time' => $start, 'opened_at' => now(), 'status' => 'open',
            'billing_unit' => 'minute', 'billed_price_per_hour' => 60]);
    }

    private function free(Room $room, string $start, string $end, ?string $date = null): int
    {
        return app(AvailabilityService::class)->availabilityForRange($room->fresh(), $date ?? $this->today, $start, $end);
    }

    private function sessionsPage()
    {
        return $this->actingAs($this->owner, 'owner')->get(route('active-sessions.index'))->assertOk();
    }

    // ------------------------------------------------------------------ 1. late checked-in sessions

    public function test_a_session_closed_before_the_next_booking_is_warned_in_time_and_bills_normally(): void
    {
        [$booking, $session] = $this->checkedIn('08:00', '10:00');

        // Not late: another use can be booked from the reservation's end on.
        $this->postBooking($this->training, '10:00', '12:00')->assertSessionHasNoErrors()->assertSessionMissing('error');
        $next = Booking::where('room_id', $this->training->id)->firstOrFail();

        $this->at('09:30');
        $this->sessionsPage()
            ->assertSee(__('app.ui.sessions.booked_until', ['time' => '10:00 AM']))
            ->assertDontSee('ls-session is-late', false)
            ->assertDontSee('ls-session-conflict', false);

        // Within the "ending soon" window: says which booking is next, and when.
        $this->at('09:50');
        $this->sessionsPage()
            ->assertSee('ls-session is-ending', false)
            ->assertSee(__('app.ui.sessions.soon_conflict', ['room' => 'Training Room', 'time' => '10:00 AM']));

        // Closed in time: billed exactly as before, from the snapshotted pricing.
        $this->at('09:55');
        $expected = app(RoomPricingService::class)->quoteSession($session->fresh(), now());
        $this->actingAs($this->owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk();

        $closed = $session->fresh();
        $this->assertSame('closed', $closed->status);
        $this->assertSame(115, (int) $closed->total_minutes);
        $this->assertEquals($expected->totalPrice, (float) $closed->total_price);
        $this->assertSame(['08:00', '10:00'], [substr($booking->fresh()->start_time, 0, 5), substr($booking->fresh()->end_time, 0, 5)], 'Booked times never change.');
        $this->assertSame('confirmed', $next->fresh()->status);

        $this->at('10:05');
        $this->sessionsPage()->assertDontSee('ls-session is-late', false);
    }

    public function test_a_late_session_warns_staff_names_the_next_booking_and_blocks_new_bookings_into_the_room(): void
    {
        [$booking, $session] = $this->checkedIn('08:00', '10:00');
        $this->postBooking($this->training, '10:00', '12:00')->assertSessionMissing('error');

        $this->at('10:10');
        $this->sessionsPage()
            ->assertSee('ls-session is-late', false)
            ->assertSee(__('app.ui.sessions.late_conflict', ['room' => 'Training Room', 'time' => '10:00 AM']))
            ->assertSee('data-late-conflicts="1"', false);

        // Never closed for the customer, never rewritten.
        $this->assertSame('open', $session->fresh()->status);
        $this->assertSame(['checked_in', '08:00', '10:00'], [$booking->fresh()->status, substr($booking->fresh()->start_time, 0, 5), substr($booking->fresh()->end_time, 0, 5)]);

        // Its end is unknown now: it holds the room for the rest of the day.
        $this->assertSame(0, $this->free($this->studio, '13:00', '14:00'));
        $this->postBooking($this->studio, '13:00', '14:00')->assertSessionHas('error');
        $this->assertSame(0, Booking::where('room_id', $this->studio->id)->count());
        $this->assertSame(1, $this->free($this->studio, '13:00', '14:00', '2026-10-06'), 'Tomorrow is unaffected.');

        // Checked out: the room is free again after it.
        $this->at('10:20');
        $this->actingAs($this->owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk();
        $this->assertSame(1, $this->free($this->studio, '13:00', '14:00'));
        $this->sessionsPage()->assertDontSee('data-late-conflicts', false);
    }

    public function test_a_late_session_with_no_later_booking_only_shows_it_is_running_late_and_bills_normally(): void
    {
        [, $session] = $this->checkedIn('08:00', '10:00');

        $this->at('10:15');
        $this->sessionsPage()
            ->assertSee('ls-session is-late', false)
            ->assertSee(__('app.ui.sessions.booked_until', ['time' => '10:00 AM']))
            ->assertDontSee('ls-session-conflict', false)
            ->assertDontSee('data-late-conflicts', false);

        $expected = app(RoomPricingService::class)->quoteSession($session->fresh(), now());
        $this->actingAs($this->owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk();
        $this->assertSame(135, (int) $session->fresh()->total_minutes, 'Billed for the real time, late minutes included.');
        $this->assertEquals($expected->totalPrice, (float) $session->fresh()->total_price);
    }

    public function test_walk_ins_without_a_reservation_get_no_end_time_warning(): void
    {
        $this->walkIn(2);

        $this->at('17:00');
        $html = $this->sessionsPage()
            ->assertDontSee('ls-session is-late', false)
            ->assertDontSee('ls-session is-ending', false)
            ->getContent();
        // (The page's script carries every sessions string as a template, so look for a filled-in one.)
        $this->assertDoesNotMatchRegularExpression('/Booked until \d/', $html);
    }

    // ------------------------------------------------------------------ 2. open walk-ins and later bookings

    public function test_open_walk_ins_take_seats_from_later_bookings_of_the_same_shared_room(): void
    {
        $walkIn = $this->walkIn(4);

        $this->assertSame(2, $this->free($this->shared, '14:00', '16:00'));
        $this->assertSame(6, $this->free($this->shared, '06:00', '08:00'), 'Never before the walk-in started.');
        $this->assertSame(6, $this->free($this->shared, '14:00', '16:00', '2026-10-06'), 'Only on its own day.');

        // The calendar and the availability report agree with the check.
        $busy = collect(app(AvailabilityService::class)->freeBusyForDay($this->shared->fresh(), $this->today));
        $this->assertSame(4, $busy->first(fn ($s) => $s['start'] <= '14:00' && $s['end'] > '14:00')['used']);
        $day = app(AvailabilityService::class)->rangeReport(collect([$this->shared->fresh()]), $this->today, $this->today, '14:00', '16:00', 3)[0]['days'][0];
        $this->assertSame([2, 'unavailable'], [$day['remaining'], $day['status']]);
        $this->assertSame(4, collect($day['conflicts'])->firstWhere('booking_id', null)['party_size']);

        // Checked out: the seats are free again.
        $walkIn->update(['status' => 'closed', 'closed_at' => now()]);
        $this->assertSame(6, $this->free($this->shared, '14:00', '16:00'));
    }

    public function test_a_later_booking_that_fits_the_remaining_seats_is_accepted(): void
    {
        $this->walkIn(4);

        $this->postBooking($this->shared, '14:00', '16:00', 2)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(1, Booking::where('room_id', $this->shared->id)->count());
        $this->assertSame(0, $this->free($this->shared, '15:00', '17:00'));
    }

    public function test_a_later_booking_that_exceeds_the_remaining_seats_is_refused_with_a_clear_reason(): void
    {
        $this->walkIn(3);
        $this->walkIn(1, '08:00');
        $this->postBooking($this->shared, '14:00', '16:00', 1)->assertSessionMissing('error'); // 1 seat left after this

        $this->postBooking($this->shared, '15:00', '17:00', 2)
            ->assertSessionHas('error', trans_choice('app.booking.shared_seats_free', 1, ['count' => 1, 'room' => 'Shared Workspace'])
                .' '.trans_choice('app.booking.walk_in_seats_held', 4, ['count' => 4]));
        $this->assertSame(1, Booking::where('room_id', $this->shared->id)->count());

        // JSON clients (the quick-booking modal) get the same reason.
        $this->actingAs($this->owner, 'owner')->postJson('/bookings', [
            'room_id' => $this->shared->id, 'hotspot_user_id' => $this->member()->id, 'party_size' => 2,
            'booking_date' => $this->today, 'start_time' => '15:00', 'end_time' => '17:00',
        ])->assertStatus(422)->assertSee(trans_choice('app.booking.shared_seats_free', 1, ['count' => 1, 'room' => 'Shared Workspace']));
    }

    public function test_a_checked_in_party_is_counted_once_by_its_headcount_until_its_booked_end(): void
    {
        $this->checkedIn('08:00', '10:00', reserved: 2, seated: 3);

        $this->assertSame(3, $this->free($this->shared, '09:00', '10:00'), 'Seated 3 counted once — not reserved 2 + seated 3.');
        $this->assertSame(6, $this->free($this->shared, '10:00', '11:00'), 'Not late: free from its booked end.');
        $this->assertSame(0, $this->free($this->training, '09:00', '10:00'));
        $this->assertSame(1, $this->free($this->training, '10:00', '11:00'));
    }

    public function test_uses_of_one_physical_room_keep_whole_room_exclusivity_with_walk_ins(): void
    {
        $this->walkIn(1);
        $elsewhere = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Board Room', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 200]);

        // Any walk-in on the shared use takes the whole room from the other uses for the day…
        $this->postBooking($this->training, '14:00', '16:00')->assertSessionHas('error');
        $this->assertSame(0, Booking::where('room_id', $this->training->id)->count());
        // …but not tomorrow, and never another physical room.
        $this->postBooking($this->training, '14:00', '16:00', date: '2026-10-06')->assertSessionMissing('error');
        $this->postBooking($elsewhere, '14:00', '16:00')->assertSessionMissing('error');
        // The shared use itself still shares its seats.
        $this->postBooking($this->shared, '14:00', '16:00', 5)->assertSessionMissing('error');
    }

    public function test_existing_bookings_keep_their_seats_through_walk_ins_and_deactivation(): void
    {
        $this->postBooking($this->shared, '14:00', '16:00', 2)->assertSessionMissing('error');
        $booking = Booking::where('room_id', $this->shared->id)->firstOrFail();
        $this->walkIn(5); // arrived after the booking was made

        $this->actingAs($this->owner, 'owner')->post(route('rooms.toggle', [$this->ws, $this->shared]))->assertRedirect();
        $this->assertFalse($this->shared->fresh()->is_available);
        $this->assertSame('confirmed', $booking->fresh()->status, 'Deactivating never touches existing bookings.');

        $payload = fn (array $over) => array_merge([
            'room_id' => $this->shared->id, 'hotspot_user_id' => $booking->hotspot_user_id, 'party_size' => 2,
            'booking_date' => $this->today, 'start_time' => '14:00', 'end_time' => '16:00', 'notes' => 'Window seats please',
        ], $over);

        // Same slot: it keeps its seats (walk-ins never push an existing booking out).
        $this->actingAs($this->owner, 'owner')->put("/bookings/{$booking->id}", $payload([]))->assertSessionMissing('error');
        $this->assertSame('Window seats please', $booking->fresh()->notes);

        // Asking for more seats is checked — and explained.
        $this->actingAs($this->owner, 'owner')->put("/bookings/{$booking->id}", $payload(['party_size' => 3]))
            ->assertSessionHas('error', trans_choice('app.booking.shared_seats_free', 1, ['count' => 1, 'room' => 'Shared Workspace'])
                .' '.trans_choice('app.booking.walk_in_seats_held', 5, ['count' => 5]));
        $this->assertSame(2, (int) $booking->fresh()->party_size);

        // New bookings of the switched-off use are refused as before.
        $this->postBooking($this->shared, '18:00', '19:00')->assertSessionHas('error', __('app.booking.room_disabled'));
    }
}
