<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Workspace;
use App\Support\ActiveSessionsQuery;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Active Sessions is a read-only projection over open SharedSession rows
 * (shared rooms) and in-progress confirmed Bookings (exclusive rooms) — no
 * new table, no new lifecycle. These tests cover the union query itself,
 * the two bundled bug fixes (check-in billing snapshot, JSON response
 * branch on booking item mutations), and the page/route/redirect wiring.
 */
class ActiveSessionsTest extends TestCase
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
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking', 'sales'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);

        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);

        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $type = 'shared', float $price = 60): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => ucfirst($type).' Room',
            'type' => $type, 'capacity' => $type === 'shared' ? 8 : 1, 'price_per_hour' => $price,
        ]);
    }

    private function member(Owner $owner, string $name = 'Member'): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => $name, 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);
    }

    public function test_a_walk_in_shared_session_appears_as_a_walk_in_session(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared');
        $user = $this->member($owner);

        SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'session_date' => today()->toDateString(), 'start_time' => now()->format('H:i'),
            'opened_at' => now(), 'status' => 'open', 'billing_unit' => 'minute', 'billed_price_per_hour' => 60,
        ]);

        $rows = ActiveSessionsQuery::build($owner->id);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()->isShared());
        $this->assertFalse($rows->first()->isFromBooking());

        $this->actingAs($owner, 'owner')->get('/active-sessions')
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee(__('app.sales.walk_in'));
    }

    public function test_checking_in_a_booking_appears_as_from_booking_and_snapshots_billing(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared', 60);
        $user = $this->member($owner);

        $booking = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(),
            'start_time' => now()->format('H:i'), 'end_time' => now()->addHour()->format('H:i'),
            'price_per_hour' => 60, 'total_hours' => 1, 'total_price' => 60, 'status' => 'confirmed',
        ]);

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/check-in", ['party_size' => 1])
            ->assertRedirect(route('active-sessions.index'));

        $session = SharedSession::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('minute', $session->billing_unit);
        $this->assertEquals(60.0, (float) $session->billed_price_per_hour);

        $rows = ActiveSessionsQuery::build($owner->id);
        $this->assertTrue($rows->first()->isFromBooking());

        // Regression test for the check-in snapshot fix: raising the room's
        // rate after check-in must not affect this already-open session.
        $room->update(['price_per_hour' => 999]);
        Carbon::setTestNow($session->opened_at->copy()->addMinutes(10));

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk();

        // 10 minutes @ 60/hr = 10.00, not the raised rate.
        $this->assertEquals(10.0, (float) $session->fresh()->total_price);
    }

    public function test_an_in_progress_exclusive_booking_appears_while_future_or_past_ones_do_not(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 100);
        $user = $this->member($owner);
        $now = Carbon::parse('2026-09-01 12:00:00');
        Carbon::setTestNow($now);

        $inProgress = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => $now->toDateString(),
            'start_time' => '11:30', 'end_time' => '13:00',
            'price_per_hour' => 100, 'total_hours' => 1.5, 'total_price' => 150, 'status' => 'confirmed',
        ]);

        Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => $now->toDateString(),
            'start_time' => '14:00', 'end_time' => '15:00',
            'price_per_hour' => 100, 'total_hours' => 1, 'total_price' => 100, 'status' => 'confirmed',
        ]);

        Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => $now->toDateString(),
            'start_time' => '09:00', 'end_time' => '10:00',
            'price_per_hour' => 100, 'total_hours' => 1, 'total_price' => 100, 'status' => 'confirmed',
        ]);

        $rows = ActiveSessionsQuery::build($owner->id);

        $this->assertCount(1, $rows);
        $this->assertFalse($rows->first()->isShared());
        $this->assertSame($inProgress->id, $rows->first()->model->id);
    }

    public function test_products_can_be_added_to_an_in_progress_exclusive_booking_via_json(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'training', 100);
        $user = $this->member($owner);
        $product = Product::create(['owner_id' => $owner->id, 'name' => 'Water', 'type' => 'product', 'price' => 10, 'is_active' => true]);

        $booking = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(),
            'start_time' => now()->subMinutes(10)->format('H:i'), 'end_time' => now()->addHour()->format('H:i'),
            'price_per_hour' => 100, 'total_hours' => 1, 'total_price' => 100, 'status' => 'confirmed',
        ]);

        $response = $this->actingAs($owner, 'owner')
            ->postJson("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('sale_items', ['product_id' => $product->id, 'quantity' => 2]);

        $item = $booking->fresh()->sale->items->first();

        $this->actingAs($owner, 'owner')
            ->deleteJson("/bookings/{$booking->id}/items/{$item->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('sale_items', ['id' => $item->id]);
    }

    public function test_checking_out_an_exclusive_booking_removes_it_from_active_sessions(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'office', 80);
        $user = $this->member($owner);

        $booking = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(),
            'start_time' => now()->subMinutes(5)->format('H:i'), 'end_time' => now()->addHour()->format('H:i'),
            'price_per_hour' => 80, 'total_hours' => 1, 'total_price' => 80, 'status' => 'confirmed',
        ]);

        $this->assertCount(1, ActiveSessionsQuery::build($owner->id));

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/status", ['status' => 'completed'])
            ->assertRedirect();

        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertCount(0, ActiveSessionsQuery::build($owner->id));
    }

    public function test_an_open_session_booking_appears_unconditionally_and_renders(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 100);
        $user = $this->member($owner);

        $booking = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(),
            'start_time' => now()->subMinutes(20)->format('H:i'), 'end_time' => null,
            'price_per_hour' => 100, 'billing_unit' => 'minute', 'billing_buffer_minutes' => 0,
            'total_hours' => 0, 'total_price' => 0, 'amount_paid' => 0, 'payment_status' => 'unpaid',
            'status' => 'open',
        ]);

        // No time-window filter at all — unlike a 'confirmed' booking, this
        // must appear regardless of start/now math, and startedAt()/the card
        // must never call endsAt() on its null end_time.
        $rows = ActiveSessionsQuery::build($owner->id);
        $this->assertCount(1, $rows);
        $this->assertFalse($rows->first()->isShared());
        $this->assertSame($booking->id, $rows->first()->model->id);
        $this->assertTrue($rows->first()->model->isOpenSession());

        $this->actingAs($owner, 'owner')->get('/active-sessions')
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee(__('app.booking.duration_type.badge'));
    }

    public function test_room_filter_only_lists_rooms_with_an_active_session(): void
    {
        $owner = $this->owner();
        $busyRoom = $this->room($owner, 'shared', 60);
        $idleRoom = $this->room($owner, 'meeting', 100);
        $user = $this->member($owner);

        SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $busyRoom->id, 'hotspot_user_id' => $user->id,
            'session_date' => today()->toDateString(), 'start_time' => now()->format('H:i'),
            'opened_at' => now(), 'status' => 'open', 'billing_unit' => 'minute', 'billed_price_per_hour' => 60,
        ]);

        $response = $this->actingAs($owner, 'owner')->get('/active-sessions');

        $response->assertOk()->assertSee($busyRoom->name)->assertDontSee($idleRoom->name);
    }

    public function test_active_sessions_count_reflects_open_and_in_progress_sessions(): void
    {
        $owner = $this->owner();
        $sharedRoom = $this->room($owner, 'shared', 60);
        $exclusiveRoom = $this->room($owner, 'studio', 90);
        $user = $this->member($owner);

        $this->assertSame(0, ActiveSessionsQuery::count($owner->id));

        SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $sharedRoom->id, 'hotspot_user_id' => $user->id,
            'session_date' => today()->toDateString(), 'start_time' => now()->format('H:i'),
            'opened_at' => now(), 'status' => 'open', 'billing_unit' => 'minute', 'billed_price_per_hour' => 60,
        ]);

        $this->assertSame(1, ActiveSessionsQuery::count($owner->id));

        Booking::create([
            'owner_id' => $owner->id, 'room_id' => $exclusiveRoom->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(),
            'start_time' => now()->subMinutes(5)->format('H:i'), 'end_time' => now()->addHour()->format('H:i'),
            'price_per_hour' => 90, 'total_hours' => 1, 'total_price' => 90, 'status' => 'confirmed',
        ]);

        $this->assertSame(2, ActiveSessionsQuery::count($owner->id));

        // An unrelated cancelled booking must never affect the count.
        Booking::create([
            'owner_id' => $owner->id, 'room_id' => $exclusiveRoom->id, 'hotspot_user_id' => $user->id,
            'party_size' => 1, 'booking_date' => today()->toDateString(),
            'start_time' => now()->subMinutes(5)->format('H:i'), 'end_time' => now()->addHour()->format('H:i'),
            'price_per_hour' => 90, 'total_hours' => 1, 'total_price' => 90, 'status' => 'cancelled',
        ]);

        $this->assertSame(2, ActiveSessionsQuery::count($owner->id));
    }

    public function test_old_shared_sessions_urls_redirect_to_active_sessions(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->get('/shared-sessions')
            ->assertRedirect(route('active-sessions.index'));

        $this->actingAs($owner, 'owner')->get('/shared-sessions/create')
            ->assertRedirect(route('active-sessions.create'));
    }

    public function test_empty_state_when_nothing_is_active(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->get('/active-sessions')
            ->assertOk()
            ->assertSee(__('app.empty.no_active_sessions'));
    }
}
