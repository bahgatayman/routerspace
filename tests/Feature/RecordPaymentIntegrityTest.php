<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\IdempotencyKey;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * recordPayment(): the balance is decided by one guarded write against the
 * row as stored at write time (A4), and a payment request is applied at most
 * once per key (A4b). True parallel requests need MySQL to exercise the row
 * lock — see the Phase A report; these tests pin the write-time decision.
 */
class RecordPaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'pay-3b1f6c2e-8d4a-4e7b-a1c9-5f2e7d8b9a01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
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

    /** total 200, discount 20 (net 180), 50 already paid → balance 130. */
    private function booking(Owner $owner, array $overrides = []): Booking
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100]);
        $user = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'M', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);

        return Booking::create(array_merge([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id, 'party_size' => 1,
            'booking_date' => today()->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00',
            'price_per_hour' => 100, 'total_hours' => 2, 'total_price' => 200, 'discount_total' => 20,
            'amount_paid' => 50, 'payment_status' => 'partial', 'status' => 'confirmed',
        ], $overrides));
    }

    private function pay(Owner $owner, Booking $booking, float $amount, ?string $key = self::KEY)
    {
        return $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/payment", array_filter(['amount' => $amount, 'idempotency_key' => $key]));
    }

    // ------------------------------------------------------------- A4

    public function test_the_balance_is_net_of_the_coupon_discount(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        $this->pay($owner, $booking, 130.01, null)->assertSessionHas('error', __('app.booking.payment.exceeds_balance', ['balance' => '130.00']));
        $this->pay($owner, $booking, 130, null)->assertSessionHas('success', __('app.booking.payment.recorded'));

        $booking->refresh();
        $this->assertSame(180.0, (float) $booking->amount_paid);
        $this->assertSame(Booking::PAYMENT_PAID, $booking->payment_status);
    }

    public function test_two_payments_that_each_fit_alone_cannot_both_be_recorded(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        // Each 100 fits the 130 balance on its own; together they'd overpay.
        $this->pay($owner, $booking, 100, 'k1-aaaaaaaa')->assertSessionHas('success');
        $this->pay($owner, $booking, 100, 'k2-bbbbbbbb')->assertSessionHas('error', __('app.booking.payment.exceeds_balance', ['balance' => '30.00']));

        $this->assertSame(150.0, (float) $booking->fresh()->amount_paid);
    }

    public function test_the_write_itself_refuses_an_amount_the_stored_row_cannot_take(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        // What a concurrent payment committing first looks like to this request:
        // the stored row has moved on since anything was read.
        $applied = Booking::whereKey($booking->id)->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereRaw('amount_paid + ? <= total_price - discount_total + 0.005', [100])->increment('amount_paid', 100);
        $this->assertSame(1, $applied);
        $again = Booking::whereKey($booking->id)->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereRaw('amount_paid + ? <= total_price - discount_total + 0.005', [100])->increment('amount_paid', 100);
        $this->assertSame(0, $again, 'The guarded write is the decision, not a value read earlier.');
        $this->assertSame(150.0, (float) $booking->fresh()->amount_paid);
    }

    public function test_cancelled_and_no_show_bookings_take_no_payment(): void
    {
        $owner = $this->owner();
        foreach (['cancelled', 'no_show'] as $status) {
            $booking = $this->booking($owner, ['status' => $status]);
            $this->pay($owner, $booking, 10, null)->assertSessionHas('error', __('app.booking.payment.cannot_record_cancelled'));
            $this->assertSame(50.0, (float) $booking->fresh()->amount_paid);
        }
    }

    public function test_a_booking_cancelled_after_the_page_loaded_is_refused_at_write_time(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        // Cancelled by someone else between the early check and the write.
        Booking::retrieved(function (Booking $b) use ($booking) {
            if ($b->id === $booking->id && $b->status === 'confirmed') {
                DB::table('bookings')->where('id', $b->id)->update(['status' => 'cancelled']);
            }
        });

        $this->pay($owner, $booking, 10)->assertSessionHas('error', __('app.booking.payment.cannot_record_cancelled'));
        $this->assertSame(50.0, (float) $booking->fresh()->amount_paid);
        $this->assertSame(0, IdempotencyKey::count(), 'The failed attempt left no claim.');
    }

    // ------------------------------------------------------------- A4b

    public function test_a_repeated_submit_records_the_payment_once(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        $this->pay($owner, $booking, 30)->assertSessionHas('success', __('app.booking.payment.recorded'));
        $this->pay($owner, $booking, 30)->assertSessionHas('success', __('app.booking.payment.already_recorded'));

        $this->assertSame(80.0, (float) $booking->fresh()->amount_paid);
        $this->assertSame(1, IdempotencyKey::where('scope', 'booking.payment')->count());
    }

    public function test_reusing_a_key_for_a_different_amount_is_refused(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        $this->pay($owner, $booking, 30)->assertSessionHas('success');
        $this->pay($owner, $booking, 40)->assertSessionHas('error', __('app.booking.payment.idempotency_mismatch'));
        $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/payment", ['amount' => 40, 'idempotency_key' => self::KEY])
            ->assertStatus(409)->assertJson(['success' => false]);

        $this->assertSame(80.0, (float) $booking->fresh()->amount_paid);
    }

    public function test_reusing_a_key_on_another_booking_is_refused(): void
    {
        $owner = $this->owner();
        $first = $this->booking($owner);
        $second = $this->booking($owner);

        $this->pay($owner, $first, 30)->assertSessionHas('success');
        $this->pay($owner, $second, 30)->assertSessionHas('error', __('app.booking.payment.idempotency_mismatch'));

        $this->assertSame(50.0, (float) $second->fresh()->amount_paid);
    }

    public function test_a_failed_attempt_leaves_its_key_free_for_a_retry(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        $this->pay($owner, $booking, 500)->assertSessionHas('error');
        $this->assertSame(0, IdempotencyKey::count());

        // Same key, corrected amount: not treated as a mismatch, since nothing was claimed.
        $this->pay($owner, $booking, 100)->assertSessionHas('success', __('app.booking.payment.recorded'));
        $this->assertSame(150.0, (float) $booking->fresh()->amount_paid);
    }

    public function test_the_payment_form_carries_a_fresh_key(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        $html = $this->actingAs($owner, 'owner')->get("/bookings/{$booking->id}")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="idempotency_key" value="[0-9a-f-]{36}"/', $html);
    }

    public function test_a_malformed_key_is_refused_and_nothing_is_recorded(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($owner);

        $this->pay($owner, $booking, 10, 'no spaces!')->assertSessionHas('error', __('app.booking.idempotency_invalid'));
        $this->assertSame(50.0, (float) $booking->fresh()->amount_paid);
    }
}
