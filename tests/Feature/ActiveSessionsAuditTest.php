<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SharedSession;
use App\Models\Workspace;
use App\Services\RoomPricingService;
use App\Services\SalesService;
use App\Services\SharedSessionBillingService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Active Sessions end-to-end audit: live bill/timer, products on a session's
 * own invoice only, idempotent adds, checkout finality, and no negative
 * time/money from bad clock data. One test per bug found in the audit.
 */
class ActiveSessionsAuditTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->now = Carbon::parse('2026-10-05 13:07:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function owner(): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0,
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace', 'booking', 'sales'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $type = 'shared', string $unit = 'hour', float $rate = 100, int $capacity = 8): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => ucfirst($type).' '.uniqid(), 'type' => $type,
            'capacity' => $capacity, 'price_per_hour' => $rate, 'billing_unit' => $type === 'shared' ? $unit : 'minute']);
    }

    private function member(Owner $owner, string $name = 'Bahgat Ayman'): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $owner->id, 'name' => $name, 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    private function product(Owner $owner, string $name = 'Spanish Coffee', float $price = 45): Product
    {
        return Product::create(['owner_id' => $owner->id, 'name' => $name, 'type' => 'product', 'price' => $price, 'is_active' => true]);
    }

    /** A walk-in shared session opened $minutes ago (as store() snapshots it). */
    private function shared(Owner $owner, Room $room, float $minutesAgo, int $party = 1): SharedSession
    {
        $opened = $this->now->copy()->subSeconds((int) round($minutesAgo * 60));

        return SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id, 'party_size' => $party,
            'session_date' => $opened->toDateString(), 'start_time' => $opened->format('H:i'), 'opened_at' => $opened, 'status' => 'open',
            'billing_unit' => $room->billing_unit, 'billing_buffer_minutes' => 0, 'billed_price_per_hour' => $room->price_per_hour,
        ]);
    }

    private function counts(): array
    {
        return ['sessions' => SharedSession::count(), 'bookings' => Booking::count(), 'sales' => Sale::count()];
    }

    private function cardsHtml(Owner $owner): string
    {
        return $this->actingAs($owner, 'owner')->get('/active-sessions')->assertOk()->getContent();
    }

    // ------------------------------------------------------------------ live bill + timer

    public function test_shared_hourly_session_card_shows_elapsed_bill_and_next_hour_consistently(): void
    {
        $owner = $this->owner();
        $session = $this->shared($owner, $this->room($owner), 137); // 2h 17m

        $html = $this->cardsHtml($owner);
        $this->assertStringContainsString('2h 17m 00s', $html, 'Timer starts at the real elapsed time.');
        $this->assertStringContainsString('EGP 300.00', $html, '3 started hours × 100.');
        $this->assertStringContainsString('data-ls-countdown="'.$this->now->copy()->addMinutes(43)->toIso8601String().'"', $html, 'Next hour in 43m.');
        $this->assertStringContainsString('data-ls-since="'.$session->opened_at->toIso8601String().'"', $html);
        $this->assertStringContainsString('data-server-now="'.$this->now->getTimestampMs().'"', $html, 'Browser timers are anchored to the server clock.');
    }

    public function test_private_open_session_card_bills_like_a_shared_session(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting');
        $room->update(['billing_unit' => 'hour']);
        $member = $this->member($owner);
        Carbon::setTestNow($this->now->copy()->subMinutes(137));

        $this->actingAs($owner, 'owner')->postJson('/bookings', ['duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $member->id])->assertOk();
        Carbon::setTestNow($this->now);

        $html = $this->cardsHtml($owner);
        $this->assertStringContainsString('2h 17m 00s', $html);
        $this->assertStringContainsString('EGP 300.00', $html);
    }

    public function test_per_minute_open_session_booking_ticks_live_like_shared_sessions(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting', 'minute', 60);
        $this->actingAs($owner, 'owner')->postJson('/bookings', ['duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id])->assertOk();

        $html = $this->cardsHtml($owner);
        $this->assertMatchesRegularExpression('/class="ls-session-big" data-ls-since="[^"]+" data-ls-rate="60"/', $html, 'Bill so far ticks live for a per-minute Open Session too.');
    }

    public function test_elapsed_time_boundaries_hour_half_hour_and_minute(): void
    {
        $svc = app(SharedSessionBillingService::class);
        $open = $this->now->copy();
        $cases = [ // minutes => [hour, half_hour, minute]
            0 => [0, 0, 0.0],
            30 => [100, 50, 50.0],
            59 => [100, 100, 98.33],
            60 => [100, 100, 100.0],
            61 => [200, 150, 101.67],
            137 => [300, 250, 228.33],
            157 => [300, 300, 261.67],
        ];
        foreach ($cases as $min => [$hour, $half, $minute]) {
            $close = $open->copy()->addMinutes($min);
            $this->assertEquals($hour, $svc->calculate($open, $close, 'hour', 100)['total_price'], "{$min}m hourly");
            $this->assertEquals($half, $svc->calculate($open, $close, 'half_hour', 100)['total_price'], "{$min}m half-hour");
            $this->assertEquals($minute, $svc->calculate($open, $close, 'minute', 100)['total_price'], "{$min}m per minute");
        }
        $this->assertEquals(100, $svc->calculate($open, $open->copy()->addSecond(), 'hour', 100)['total_price'], 'Any started time costs the first block.');
    }

    // ------------------------------------------------------------------ bad clock data

    public function test_a_future_start_never_produces_negative_time_money_or_a_countdown(): void
    {
        $owner = $this->owner();
        $minuteRoom = $this->room($owner, 'shared', 'minute', 60);
        $hourRoom = $this->room($owner);
        $s1 = $this->shared($owner, $minuteRoom, -44); // legacy row: "opened" 44 min in the future
        $s2 = $this->shared($owner, $hourRoom, -44);

        $pricing = app(RoomPricingService::class);
        $this->assertSame(0.0, $pricing->quoteSession($s1, now())->totalPrice, 'Per-minute: never negative.');
        $this->assertSame(0.0, $pricing->quoteSession($s2, now())->totalPrice);
        $this->assertNull($pricing->nextSessionChargeAt($s2, now()), 'No "Next hour in 1h 44m" against a 0m timer.');

        $html = $this->cardsHtml($owner);
        $this->assertStringNotContainsString('EGP -', $html);
        $this->assertStringNotContainsString('data-ls-countdown', $html);
        $this->assertStringContainsString('0m 00s', $html);

        // Closing such a session can never record negative revenue.
        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$s1->id}/close")->assertOk();
        $this->assertGreaterThanOrEqual(0, (float) Booking::findOrFail($s1->fresh()->booking_id)->total_price);
        $this->assertGreaterThanOrEqual(0, (float) $s1->fresh()->total_minutes);
    }

    public function test_walk_in_start_time_in_the_future_is_stored_as_now(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $this->actingAs($owner, 'owner')->post('/shared-sessions', [
            'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id,
            'session_date' => $this->now->toDateString(), 'start_time' => $this->now->copy()->addHours(3)->format('H:i'),
        ])->assertRedirect();

        $this->assertEquals($this->now, SharedSession::firstOrFail()->opened_at);
    }

    public function test_the_app_runs_on_local_time(): void
    {
        $this->assertSame('Africa/Cairo', config('app.timezone'));
    }

    // ------------------------------------------------------------------ products

    public function test_quick_add_only_changes_the_existing_sessions_invoice(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $session = $this->shared($owner, $room, 30);
        $coffee = $this->product($owner);
        $latte = $this->product($owner, 'Latte', 40);
        $before = $this->counts();

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $coffee->id, 'quantity' => 1])->assertOk();
        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $latte->id, 'quantity' => 2])->assertOk();
        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $coffee->id, 'quantity' => 1])->assertOk();

        $after = $this->counts();
        $this->assertSame($before['sessions'], $after['sessions'], 'No new session.');
        $this->assertSame($before['bookings'], $after['bookings'], 'No booking / room charge.');
        $this->assertSame(1, $after['sales'], 'Exactly one invoice for the session.');
        $sale = $session->fresh()->sale;
        $this->assertEquals(45 + 80 + 45, (float) $sale->total);
        $this->assertSame(3, $sale->items()->count());

        // Card: one session, room charge unchanged, products added on top.
        $html = $this->cardsHtml($owner);
        $this->assertSame(1, substr_count($html, 'data-ls-item="sessions"'));
        $this->assertStringContainsString('EGP 270.00', $html, '100 room + 170 products.');
    }

    public function test_products_on_an_open_session_booking_go_to_that_booking_never_to_a_shared_tab_with_the_same_id(): void
    {
        $owner = $this->owner();
        $shared = $this->shared($owner, $this->room($owner), 10); // SharedSession #1
        $private = $this->room($owner, 'meeting');
        $this->actingAs($owner, 'owner')->postJson('/bookings', ['duration_type' => 'open', 'room_id' => $private->id, 'hotspot_user_id' => $this->member($owner, 'Other')->id])->assertOk();
        $booking = Booking::where('status', 'open')->firstOrFail();
        $this->assertSame($shared->id, $booking->id, 'Same numeric id on purpose.');

        $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/items", ['product_id' => $this->product($owner)->id, 'quantity' => 1])->assertOk();
        $this->assertSame(1, $booking->fresh()->sale->items()->count());
        $this->assertNull($shared->fresh()->sale, 'The shared customer is never charged.');

        // The checkout window routes item calls by session type (regression for the hardcoded /shared-sessions URL).
        $html = $this->cardsHtml($owner);
        $this->assertStringContainsString('fetch(`${sessionBaseUrl()}/items`', $html);
        $this->assertStringNotContainsString('fetch(`/shared-sessions/${currentSessionId}/items', $html);
    }

    public function test_repeated_and_duplicated_adds_are_idempotent(): void
    {
        $owner = $this->owner();
        $session = $this->shared($owner, $this->room($owner), 30);
        $coffee = $this->product($owner);
        $send = fn (string $key) => $this->actingAs($owner, 'owner')
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $coffee->id, 'quantity' => 1]);

        $send('click-1')->assertOk()->assertJsonMissing(['duplicate' => true]);
        $send('click-1')->assertOk()->assertJson(['duplicate' => true]); // second tab / retry / double submit
        $send('click-1')->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, SaleItem::count());

        $send('click-2')->assertOk(); // a genuinely new click
        $this->assertSame(2, SaleItem::count());
        $this->assertSame(1, Sale::count());

        // Same for private-room bookings.
        $room = $this->room($owner, 'meeting');
        $this->actingAs($owner, 'owner')->postJson('/bookings', ['duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner, 'B')->id])->assertOk();
        $b = Booking::where('status', 'open')->firstOrFail();
        foreach ([1, 2] as $_) {
            $this->actingAs($owner, 'owner')->withHeader('X-Idempotency-Key', 'b-1')->postJson("/bookings/{$b->id}/items", ['product_id' => $coffee->id, 'quantity' => 1])->assertOk();
        }
        $this->assertSame(1, $b->fresh()->sale->items()->count());
    }

    public function test_a_stale_session_object_never_creates_a_second_invoice(): void
    {
        $owner = $this->owner();
        $session = $this->shared($owner, $this->room($owner), 5);
        $staleA = SharedSession::find($session->id);
        $staleB = SharedSession::find($session->id);
        $sales = app(SalesService::class);

        $a = $sales->saleForSharedSession($staleA);
        $b = $sales->saleForSharedSession($staleB); // loaded before $a existed
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Sale::count());
    }

    public function test_quantity_change_and_removal_stay_on_the_same_invoice(): void
    {
        $owner = $this->owner();
        $session = $this->shared($owner, $this->room($owner), 20);
        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $this->product($owner)->id, 'quantity' => 1])->assertOk();
        $item = SaleItem::firstOrFail();
        $before = $this->counts();

        $this->actingAs($owner, 'owner')->patchJson("/shared-sessions/{$session->id}/items/{$item->id}", ['quantity' => 3])->assertOk();
        $this->assertEquals(135, (float) $session->fresh()->sale->total);
        $this->actingAs($owner, 'owner')->deleteJson("/shared-sessions/{$session->id}/items/{$item->id}")->assertOk();
        $this->assertEquals(0, (float) $session->fresh()->sale->total);
        $this->assertSame($before, $this->counts());
    }

    public function test_each_product_line_on_a_card_has_a_remove_icon_for_its_own_invoice(): void
    {
        $owner = $this->owner();
        $session = $this->shared($owner, $this->room($owner), 32);
        $latte = $this->product($owner, 'Latte', 90);
        foreach ([1, 2, 3] as $_) {
            $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $latte->id, 'quantity' => 1])->assertOk();
        }
        $items = SaleItem::orderBy('id')->get();

        $html = $this->cardsHtml($owner);
        foreach ($items as $item) {
            $this->assertStringContainsString('data-url="/shared-sessions/'.$session->id.'/items/'.$item->id.'"', $html);
        }
        $this->assertStringContainsString(__('app.ui.sessions.remove_item', ['product' => 'Latte']), $html);

        // What the icon calls: only that line goes, the session stays, totals follow.
        $this->actingAs($owner, 'owner')->deleteJson("/shared-sessions/{$session->id}/items/{$items[1]->id}")->assertOk();
        $this->assertSame(2, $session->fresh()->sale->items()->count());
        $this->assertEquals(180, (float) $session->fresh()->sale->total);
        $this->assertSame(1, SharedSession::count());
        $this->assertSame(0, Booking::count());
    }

    // ------------------------------------------------------------------ refresh / checkout / finality

    public function test_refreshing_never_changes_billing_state(): void
    {
        $owner = $this->owner();
        $this->shared($owner, $this->room($owner), 95);
        $before = $this->counts();

        $first = $this->cardsHtml($owner);
        foreach (range(1, 5) as $_) {
            $this->assertSame($first, $this->cardsHtml($owner));
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_checkout_charges_once_and_the_finalized_invoice_is_locked(): void
    {
        $owner = $this->owner();
        $session = $this->shared($owner, $this->room($owner), 137);
        $coffee = $this->product($owner);
        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $coffee->id, 'quantity' => 2])->assertOk();

        $preview = $this->actingAs($owner, 'owner')->getJson("/shared-sessions/{$session->id}/close-preview")->assertOk();
        $this->assertSame('390.00', $preview->json('grand_total'), '300 room + 90 products.');

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk();
        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertStatus(409); // second tab / double click

        $this->assertSame(1, Booking::count(), 'One room charge.');
        $booking = Booking::firstOrFail();
        $this->assertEquals(300, (float) $booking->total_price);
        $this->assertEquals(390, $booking->fresh('sale')->grandTotal());

        // Finalized: no more products, no more time.
        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $coffee->id, 'quantity' => 1])->assertNotFound();
        $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/items", ['product_id' => $coffee->id, 'quantity' => 1])->assertStatus(422);
        $this->assertSame(2, (int) $booking->fresh()->sale->items()->sum('quantity'));

        Carbon::setTestNow($this->now->copy()->addHours(5));
        $this->assertEquals(390, $booking->fresh('sale')->grandTotal(), 'A checked-out invoice never keeps accumulating.');
        $this->assertStringNotContainsString('data-ls-item="sessions"', $this->cardsHtml($owner));
    }

    public function test_open_session_booking_checkout_is_final(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'meeting');
        $room->update(['billing_unit' => 'hour']);
        Carbon::setTestNow($this->now->copy()->subMinutes(61));
        $this->actingAs($owner, 'owner')->postJson('/bookings', ['duration_type' => 'open', 'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner)->id])->assertOk();
        Carbon::setTestNow($this->now);
        $b = Booking::firstOrFail();

        $this->actingAs($owner, 'owner')->postJson("/bookings/{$b->id}/close")->assertOk();
        $this->actingAs($owner, 'owner')->postJson("/bookings/{$b->id}/close")->assertStatus(409);
        $this->assertEquals(200, (float) $b->fresh()->total_price, '1h 01m → 2 hours.');
        $this->actingAs($owner, 'owner')->postJson("/bookings/{$b->id}/items", ['product_id' => $this->product($owner)->id, 'quantity' => 1])->assertStatus(422);
        $this->assertSame(1, Booking::count());
    }

    // ------------------------------------------------------------------ which rows appear

    public function test_bookings_appear_only_while_in_progress_and_walk_ins_while_open(): void
    {
        $owner = $this->owner();
        $meeting = $this->room($owner, 'meeting');
        $member = $this->member($owner);
        $mk = fn (string $start, string $end) => Booking::create([
            'owner_id' => $owner->id, 'room_id' => $meeting->id, 'hotspot_user_id' => $member->id, 'party_size' => 1,
            'booking_date' => $this->now->toDateString(), 'start_time' => $start, 'end_time' => $end,
            'price_per_hour' => 100, 'total_hours' => 1, 'total_price' => 100, 'status' => 'confirmed',
        ]);
        $mk('12:30', '13:30'); // in progress
        $mk('14:00', '15:00'); // later today
        $mk('10:00', '11:00'); // already over
        $this->shared($owner, $this->room($owner), 15);

        $html = $this->cardsHtml($owner);
        $this->assertSame(2, substr_count($html, 'data-ls-item="sessions"'), 'One in-progress booking + one open walk-in, no future/past bookings.');
    }

    public function test_shared_room_seats_are_enforced(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'shared', 'hour', 100, 2);
        $post = fn (int $party) => $this->actingAs($owner, 'owner')->post('/shared-sessions', [
            'room_id' => $room->id, 'hotspot_user_id' => $this->member($owner, 'M'.uniqid())->id, 'party_size' => $party,
            'session_date' => $this->now->toDateString(), 'start_time' => $this->now->format('H:i'),
        ]);

        $post(2)->assertRedirect();
        $post(1)->assertSessionHas('error');
        $this->assertSame(1, SharedSession::where('status', 'open')->count());
    }
}
