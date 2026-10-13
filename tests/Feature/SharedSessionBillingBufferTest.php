<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Staff;
use App\Models\Workspace;
use App\Services\RoomPricingService;
use App\Services\SharedSessionBillingService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Shared-room Billing Buffer (grace period): minutes allowed past each block
 * boundary before the next block is charged. One engine
 * (SharedSessionBillingService via RoomPricingService) prices the Active
 * Sessions estimate, the close preview and the close itself.
 */
class SharedSessionBillingBufferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
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
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace', 'booking'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $unit = 'hour', int $buffer = 15, float $rate = 10): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Lounge '.uniqid(), 'type' => 'shared',
            'capacity' => 8, 'price_per_hour' => $rate, 'billing_unit' => $unit, 'billing_buffer_minutes' => $buffer,
        ]);
    }

    /** An open session snapshotted from $room exactly as store() does. */
    private function openSession(Owner $owner, Room $room, Carbon $openedAt): SharedSession
    {
        $user = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Cust', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);

        return SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'session_date' => $openedAt->toDateString(), 'start_time' => $openedAt->format('H:i'),
            'opened_at' => $openedAt, 'status' => 'open',
            'billing_unit' => $room->billing_unit, 'billing_buffer_minutes' => $room->billing_buffer_minutes,
            'billed_price_per_hour' => $room->price_per_hour,
        ]);
    }

    private function price(float $minutes, string $unit, int $buffer, float $rate = 10): float
    {
        $open = Carbon::parse('2026-08-01 10:00:00');

        return app(SharedSessionBillingService::class)
            ->calculate($open, $open->copy()->addSeconds((int) round($minutes * 60)), $unit, $rate, $buffer)['total_price'];
    }

    // ------------------------------------------------------------------ engine

    public static function hourlyCases(): array
    {
        return [
            // buffer 0 — today's behaviour, unchanged
            'b0 · 0 min' => [0, 0, 0],
            'b0 · 1 min' => [0, 1, 10],
            'b0 · 60 min' => [0, 60, 10],
            'b0 · 61 min' => [0, 61, 20],
            'b0 · 120 min' => [0, 120, 20],
            // buffer 15 — the brief's table
            'b15 · 0 min' => [15, 0, 0],
            'b15 · 1 min' => [15, 1, 10],
            'b15 · 10 min (shorter than the buffer)' => [15, 10, 10],
            'b15 · 60 min' => [15, 60, 10],
            'b15 · 70 min' => [15, 70, 10],
            'b15 · 75 min (exactly 1h + buffer)' => [15, 75, 10],
            'b15 · 75.98 min (still inside the 15th minute)' => [15, 75.98, 10],
            'b15 · 76 min' => [15, 76, 20],
            'b15 · 120 min' => [15, 120, 20],
            'b15 · 135 min (exactly 2h + buffer)' => [15, 135, 20],
            'b15 · 136 min' => [15, 136, 30],
            'b15 · 10h 15m' => [15, 615, 100],
            'b15 · 10h 16m' => [15, 616, 110],
            // other buffers
            'b5 · 65 min' => [5, 65, 10],
            'b5 · 66 min' => [5, 66, 20],
            'b10 · 70 min' => [10, 70, 10],
            'b10 · 71 min' => [10, 71, 20],
            'b30 · 90 min' => [30, 90, 10],
            'b30 · 91 min' => [30, 91, 20],
            'b30 · 150 min' => [30, 150, 20],
            'b30 · 151 min' => [30, 151, 30],
            'custom b7 · 67 min' => [7, 67, 10],
            'custom b7 · 68 min' => [7, 68, 20],
            'custom b59 · 119 min' => [59, 119, 10],
            'custom b59 · 120 min' => [59, 120, 20],
        ];
    }

    #[DataProvider('hourlyCases')]
    public function test_hourly_billing_with_buffer(int $buffer, float $minutes, float $expected): void
    {
        $this->assertEquals($expected, $this->price($minutes, 'hour', $buffer));
    }

    public function test_buffer_on_half_hour_blocks(): void
    {
        $this->assertEquals(5.0, $this->price(40, 'half_hour', 10));  // 30 + 10 grace → 1 block
        $this->assertEquals(10.0, $this->price(41, 'half_hour', 10));
        $this->assertEquals(10.0, $this->price(70, 'half_hour', 10));
        $this->assertEquals(15.0, $this->price(71, 'half_hour', 10));
    }

    public function test_buffer_is_ignored_for_per_minute_billing_and_capped_below_a_block(): void
    {
        $this->assertEquals(12.5, $this->price(75, 'minute', 15));
        // A stray buffer ≥ the block is capped just below it (here 29 min),
        // so the next block can never be skipped.
        $this->assertEquals(5.0, $this->price(59, 'half_hour', 45));
        $this->assertEquals(10.0, $this->price(60, 'half_hour', 45));
    }

    public function test_buffer_zero_matches_the_original_formula_exactly(): void
    {
        $svc = app(SharedSessionBillingService::class);
        $open = Carbon::parse('2026-08-01 10:00:00');
        foreach ([0.5, 29.99, 30.01, 59.5, 60, 60.01, 90.5, 181] as $m) {
            $close = $open->copy()->addSeconds((int) round($m * 60));
            foreach (['minute', 'half_hour', 'hour'] as $unit) {
                $totalMinutes = round($open->diffInSeconds($close) / 60, 2);
                $unitMinutes = $svc->unitMinutes($unit);
                $legacy = $unitMinutes === 1
                    ? round(round($totalMinutes / 60, 4) * 10, 2)
                    : round(round(ceil($totalMinutes / $unitMinutes) * $unitMinutes / 60, 4) * 10, 2);
                $this->assertEquals($legacy, $svc->calculate($open, $close, $unit, 10)['total_price'], "{$m} min {$unit}");
                $this->assertEquals($legacy, $svc->calculate($open, $close, $unit, 10, 0)['total_price'], "{$m} min {$unit} b0");
            }
        }
    }

    public function test_next_charge_time(): void
    {
        $svc = app(SharedSessionBillingService::class);
        $open = Carbon::parse('2026-08-01 23:30:00'); // crosses midnight

        $this->assertEquals($open->copy()->addMinutes(76), $svc->nextChargeAt($open, $open->copy()->addMinutes(72), 'hour', 15));
        $this->assertEquals($open->copy()->addMinutes(136), $svc->nextChargeAt($open, $open->copy()->addMinutes(76), 'hour', 15));
        $this->assertEquals($open->copy()->addMinutes(76), $svc->nextChargeAt($open, $open->copy(), 'hour', 15));
        $this->assertEquals($open->copy()->addMinutes(120), $svc->nextChargeAt($open, $open->copy()->addMinutes(72), 'hour', 0));
        $this->assertNull($svc->nextChargeAt($open, $open->copy()->addMinutes(72), 'minute', 15));
    }

    // ------------------------------------------------------------------ preview + close + estimate agree

    public function test_preview_close_and_estimate_use_the_same_buffer_aware_price(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $now = Carbon::parse('2026-08-01 01:15:00');
        $session = $this->openSession($owner, $room, $now->copy()->subMinutes(75)); // opened 00:00 → crosses into the night
        Carbon::setTestNow($now);

        $this->assertEquals(10.0, app(RoomPricingService::class)->quoteSession($session, now())->totalPrice);
        $this->actingAs($owner, 'owner')->getJson("/shared-sessions/{$session->id}/close-preview")
            ->assertOk()->assertJson(['total_price' => '10.00']);

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/close", ['total_price' => 0])->assertOk();

        $session->refresh();
        $this->assertEquals(10.0, (float) $session->total_price);
        $booking = Booking::findOrFail($session->booking_id);
        $this->assertEquals(10.0, (float) $booking->total_price);
        $this->assertEquals(10.0, (float) $booking->amount_paid, 'Revenue records the buffer-aware amount.');
    }

    public function test_one_minute_past_the_buffer_charges_the_next_hour_at_close(): void
    {
        $owner = $this->owner();
        $now = Carbon::parse('2026-08-01 12:00:00');
        $session = $this->openSession($owner, $this->room($owner), $now->copy()->subMinutes(76));
        Carbon::setTestNow($now);

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk();
        $this->assertEquals(20.0, (float) $session->fresh()->total_price);
    }

    public function test_the_buffer_is_snapshotted_when_the_session_opens(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'hour', 15);
        $member = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Walk', 'phone' => '0109999'.rand(1000, 9999), 'password' => 'pass1234']);

        $this->actingAs($owner, 'owner')->post('/shared-sessions', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'session_date' => now()->toDateString(), 'start_time' => now()->format('H:i'),
        ])->assertRedirect();
        $session = SharedSession::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame(15, $session->billing_buffer_minutes);

        // Owner removes the buffer while the session is open — it keeps its own.
        $room->update(['billing_buffer_minutes' => 0]);
        $session->update(['opened_at' => now()->subMinutes(70)]);
        $this->assertEquals(10.0, app(RoomPricingService::class)->quoteSession($session->fresh(), now())->totalPrice);
    }

    public function test_sessions_opened_before_the_feature_bill_as_before(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'hour', 15);
        $now = Carbon::parse('2026-08-01 12:00:00');
        $session = $this->openSession($owner, $room, $now->copy()->subMinutes(70));
        $session->update(['billing_buffer_minutes' => null]); // pre-migration row
        Carbon::setTestNow($now);

        $this->assertEquals(20.0, app(RoomPricingService::class)->quoteSession($session->fresh(), now())->totalPrice);
    }

    public function test_active_sessions_card_keeps_a_hidden_grace_aware_refresh_timer_without_showing_it(): void
    {
        $owner = $this->owner();
        $now = Carbon::parse('2026-08-01 12:00:00');
        $this->openSession($owner, $this->room($owner), $now->copy()->subMinutes(72));
        Carbon::setTestNow($now);

        // The next-hour/grace text is no longer shown on the card, but the timer that
        // reloads the page when the next (grace-adjusted) block starts must still be there.
        $this->actingAs($owner, 'owner')->get('/active-sessions')->assertOk()
            ->assertSee('data-ls-countdown="'.$now->copy()->addMinutes(4)->toIso8601String().'"', false)
            ->assertDontSee(__('app.session.grace', ['count' => 15]))
            ->assertDontSee(__('app.session.next_hour', ['d' => '4'.__('app.ui.unit_m')]));
    }

    // ------------------------------------------------------------------ settings

    private function roomPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Lounge', 'type' => 'shared', 'capacity' => 8, 'price_per_hour' => 10,
            'billing_unit' => 'hour', 'billing_buffer_minutes' => 15, 'pricing_model' => 'hourly',
        ], $over);
    }

    public function test_owner_sets_the_buffer_from_the_room_form(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        $this->actingAs($owner, 'owner')->post("/workspaces/{$ws->id}/rooms", $this->roomPayload())->assertRedirect();
        $room = Room::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame(15, $room->billing_buffer_minutes);

        $this->actingAs($owner, 'owner')->put("/workspaces/{$ws->id}/rooms/{$room->id}", $this->roomPayload(['billing_buffer_minutes' => 7]))->assertRedirect();
        $this->assertSame(7, $room->fresh()->billing_buffer_minutes);

        $this->actingAs($owner, 'owner')->get("/workspaces/{$ws->id}/rooms/{$room->id}/edit")->assertOk()
            ->assertSee(__('app.workspace.billing_buffer'))->assertSee('id="billing_buffer_minutes" value="7"', false);
    }

    public function test_invalid_buffers_are_rejected(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);
        $post = fn (array $over) => $this->actingAs($owner, 'owner')->post("/workspaces/{$ws->id}/rooms", $this->roomPayload($over));

        $post(['billing_buffer_minutes' => -5])->assertSessionHasErrors('billing_buffer_minutes');
        $post(['billing_buffer_minutes' => 'abc'])->assertSessionHasErrors('billing_buffer_minutes');
        $post(['billing_buffer_minutes' => 60])->assertSessionHasErrors('billing_buffer_minutes');
        $post(['billing_unit' => 'half_hour', 'billing_buffer_minutes' => 30])->assertSessionHasErrors('billing_buffer_minutes');
        $this->assertSame(0, Room::where('owner_id', $owner->id)->count());

        // Not meaningful → stored as 0.
        $post(['billing_unit' => 'minute', 'name' => 'Per minute'])->assertRedirect();
        $post(['type' => 'meeting', 'name' => 'Meeting'])->assertRedirect();
        $this->assertSame([0, 0], Room::where('owner_id', $owner->id)->orderBy('id')->pluck('billing_buffer_minutes')->all());
    }

    public function test_tenant_isolation_and_permissions(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $theirs = $this->room($other, 'hour', 10);

        $this->actingAs($owner, 'owner')
            ->put("/workspaces/{$theirs->workspace_id}/rooms/{$theirs->id}", $this->roomPayload(['billing_buffer_minutes' => 30]))
            ->assertNotFound();
        $this->assertSame(10, $theirs->fresh()->billing_buffer_minutes);

        auth('owner')->logout();
        $mine = $this->room($owner, 'hour', 10);
        $role = Role::whereNull('owner_id')->where('key', 'receptionist')->firstOrFail();
        $staff = Staff::create(['owner_id' => $owner->id, 'role_id' => $role->id, 'name' => 'R', 'email' => 'r'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        $staff->syncPermissionsFromRole();

        $this->actingAs($staff, 'staff')->put("/workspaces/{$mine->workspace_id}/rooms/{$mine->id}", $this->roomPayload(['billing_buffer_minutes' => 30]));
        $this->assertSame(10, $mine->fresh()->billing_buffer_minutes, 'Staff without workspaces.manage cannot change billing settings.');
    }
}
