<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientPackageBalanceException;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\Notification;
use App\Models\Owner;
use App\Models\PackageTemplate;
use App\Models\PackageUsage;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Staff;
use App\Models\Workspace;
use App\Services\AnalyticsPeriod;
use App\Services\HourPackageService;
use App\Services\NotificationService;
use App\Services\RevenueAnalyticsService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Member Hour Packages: templates, assignment snapshots, booking/session
 * usage in minutes, edit reconciliation, cancellation returns, concurrency,
 * revenue-when-used, notifications, tenancy and permissions.
 */
class HourPackagesTest extends TestCase
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

    private function owner(array $features = ['workspace', 'booking', 'sales']): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0,
            'is_active' => true, 'sort_order' => 1, 'features' => $features,
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $type = 'meeting', float $rate = 100): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room '.uniqid(), 'type' => $type, 'capacity' => 6, 'price_per_hour' => $rate]);
    }

    private function member(Owner $owner, string $name = 'Ahmed'): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $owner->id, 'name' => $name, 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    /** 30h for 1,500 valid 30 days from today, assigned through the real endpoint. */
    private function package(Owner $owner, HotspotUser $member, array $overrides = []): MemberPackage
    {
        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/packages", array_merge([
            'name' => '30 Hours Monthly', 'hours' => 30, 'price_paid' => 1500,
            'starts_on' => today()->toDateString(), 'expires_on' => today()->addDays(29)->toDateString(),
        ], $overrides))->assertRedirect();

        return MemberPackage::where('owner_id', $owner->id)->where('hotspot_user_id', $member->id)->latest('id')->firstOrFail();
    }

    private function book(Owner $owner, Room $room, HotspotUser $member, string $start, string $end, ?MemberPackage $pkg, ?string $date = null)
    {
        return $this->actingAs($owner, 'owner')->postJson('/bookings', [
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => $date ?? today()->addDay()->toDateString(),
            'start_time' => $start, 'end_time' => $end,
            'member_package_id' => $pkg?->id,
        ]);
    }

    private function edit(Owner $owner, Booking $b, string $start, string $end, ?MemberPackage $pkg)
    {
        return $this->actingAs($owner, 'owner')->put("/bookings/{$b->id}", [
            'room_id' => $b->room_id, 'hotspot_user_id' => $b->hotspot_user_id,
            'booking_date' => $b->booking_date->toDateString(),
            'start_time' => $start, 'end_time' => $end,
            'member_package_id' => $pkg?->id,
        ]);
    }

    // --------------------------------------------------------------- templates

    public function test_templates_create_edit_toggle_and_delete_only_when_unused(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        $this->actingAs($owner, 'owner')->post('/packages', [
            'name' => '30 Hours Monthly', 'hours' => 30, 'price' => 1500, 'validity_days' => 30,
            'room_scope' => 'specific', 'room_ids' => [$room->id], 'is_active' => 1,
        ])->assertRedirect('/packages');

        $t = PackageTemplate::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame(1800, $t->total_minutes);
        $this->assertSame([$room->id], $t->room_ids);
        $this->assertTrue($t->is_active);

        $this->actingAs($owner, 'owner')->put("/packages/{$t->id}", [
            'name' => '10.5 Hours', 'hours' => 10.5, 'price' => 500, 'validity_days' => 7, 'room_scope' => 'all', 'is_active' => 1,
        ])->assertRedirect('/packages');
        $t->refresh();
        $this->assertSame(630, $t->total_minutes);
        $this->assertNull($t->room_ids);

        $this->actingAs($owner, 'owner')->post("/packages/{$t->id}/toggle");
        $this->assertFalse($t->fresh()->is_active);

        $this->actingAs($owner, 'owner')->get('/packages')->assertOk()->assertSee('10.5 Hours');

        // Used → can't delete.
        $this->actingAs($owner, 'owner')->post("/packages/{$t->id}/toggle");
        $this->assertTrue($t->fresh()->is_active);
        $member = $this->member($owner);
        $this->package($owner, $member, ['package_template_id' => $t->id]);
        $this->actingAs($owner, 'owner')->delete("/packages/{$t->id}")->assertSessionHas('error');
        $this->assertNotNull($t->fresh());

        $unused = PackageTemplate::create(['owner_id' => $owner->id, 'name' => 'X', 'total_minutes' => 60, 'price' => 10, 'validity_days' => 5]);
        $this->actingAs($owner, 'owner')->delete("/packages/{$unused->id}")->assertRedirect('/packages');
        $this->assertNull($unused->fresh());
    }

    // ----------------------------------------------------------------- assign

    public function test_assignment_snapshots_terms_and_template_edits_do_not_change_sold_packages(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $t = PackageTemplate::create(['owner_id' => $owner->id, 'name' => '30 Hours Monthly', 'total_minutes' => 1800, 'price' => 1500, 'validity_days' => 30, 'room_ids' => [$room->id]]);

        $pkg = $this->package($owner, $member, ['package_template_id' => $t->id]);
        $t->update(['name' => 'Renamed', 'total_minutes' => 600, 'price' => 99, 'room_ids' => null]);

        $pkg->refresh();
        $this->assertSame('30 Hours Monthly', $pkg->name);
        $this->assertSame(1800, $pkg->total_minutes);
        $this->assertSame('1500.00', (string) $pkg->price_paid);
        $this->assertSame([$room->id], $pkg->room_ids);
        $this->assertSame(1800, $pkg->remainingMinutes());
        $this->assertSame(MemberPackage::STATUS_ACTIVE, $pkg->status());

        $purchase = PackageUsage::where('member_package_id', $pkg->id)->firstOrFail();
        $this->assertSame(PackageUsage::PURCHASE, $purchase->action);
        $this->assertSame(1800, $purchase->balance_after);

        // Custom package (no template), fractional hours.
        $custom = $this->package($owner, $member, ['name' => 'Trial', 'hours' => 2.5, 'price_paid' => 100]);
        $this->assertNull($custom->package_template_id);
        $this->assertSame(150, $custom->total_minutes);

        $profile = $this->actingAs($owner, 'owner')->get("/users/{$member->id}")->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->component('Users/Show')->where('showPackages', true));
        $names = collect($profile->inertiaProps('packages'))->pluck('name');
        $this->assertContains('30 Hours Monthly', $names);
        $this->assertContains('Trial', $names);
        $this->assertStringContainsString('30h', json_encode($profile->inertiaProps('packages'), JSON_UNESCAPED_UNICODE));
    }

    public function test_status_is_derived(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);

        $this->assertSame(MemberPackage::STATUS_SCHEDULED, $this->package($owner, $member, ['starts_on' => today()->addDays(2)->toDateString(), 'expires_on' => today()->addDays(9)->toDateString()])->status());
        $this->assertSame(MemberPackage::STATUS_EXPIRED, $this->package($owner, $member, ['starts_on' => today()->subDays(9)->toDateString(), 'expires_on' => today()->subDay()->toDateString()])->status());

        $pkg = $this->package($owner, $member);
        $pkg->update(['used_minutes' => 1800]);
        $this->assertSame(MemberPackage::STATUS_EXHAUSTED, $pkg->status());
        $pkg->update(['cancelled_at' => now()]);
        $this->assertSame(MemberPackage::STATUS_CANCELLED, $pkg->status());
    }

    // ------------------------------------------------------------------ usage

    public function test_bookings_deduct_their_minutes_including_fractional_hours(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $pkg = $this->package($owner, $member);

        $this->book($owner, $room, $member, '09:00', '10:00', $pkg)->assertOk()->assertJson(['success' => true]);
        $this->book($owner, $room, $member, '10:00', '12:00', $pkg)->assertOk();
        $this->book($owner, $room, $member, '12:00', '13:30', $pkg)->assertOk();

        $pkg->refresh();
        $this->assertSame(270, $pkg->used_minutes);
        $this->assertSame(1530, $pkg->remainingMinutes());
        $this->assertSame('25h 30m', $pkg->remainingLabel());

        $rows = PackageUsage::where('member_package_id', $pkg->id)->where('action', PackageUsage::BOOKING_USAGE)->orderBy('id')->get();
        $this->assertSame([60, 120, 90], $rows->pluck('minutes')->all());
        $this->assertSame([1800, 1740, 1620], $rows->pluck('balance_before')->all());
        $this->assertSame([1740, 1620, 1530], $rows->pluck('balance_after')->all());

        $b = Booking::where('owner_id', $owner->id)->orderBy('id')->first();
        $this->assertSame(Booking::METHOD_PACKAGE, $b->payment_method);
        $this->assertSame($pkg->id, $b->member_package_id);
        $this->assertSame(Booking::PAYMENT_PAID, $b->payment_status);
        $this->assertEquals(50.0, (float) $b->total_price, '1h of a 30h/1500 package is worth 50.');
        $this->assertEquals(50.0, (float) $b->amount_paid);
        $this->assertStringContainsString('30 Hours Monthly', $b->pricing_note);
        $this->assertSame($b->id, $rows->first()->booking_id);
    }

    public function test_insufficient_balance_is_refused_with_a_message_and_changes_nothing(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $pkg = $this->package($owner, $member, ['hours' => 1.5, 'price_paid' => 90]);

        $res = $this->book($owner, $room, $member, '09:00', '11:00', $pkg);
        $res->assertStatus(422);
        $this->assertStringContainsString('1h 30m', $res->json('message'));

        $this->assertSame(0, Booking::where('owner_id', $owner->id)->count());
        $this->assertSame(0, $pkg->fresh()->used_minutes);
        $this->assertSame(1, PackageUsage::where('member_package_id', $pkg->id)->count(), 'Only the purchase row.');
    }

    public function test_expired_cancelled_scheduled_and_wrong_room_packages_are_rejected(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $other = $this->room($owner);

        // Valid today but expires before the booking date (validity = booking's date).
        $short = $this->package($owner, $member, ['expires_on' => today()->toDateString()]);
        $this->book($owner, $room, $member, '09:00', '10:00', $short)->assertStatus(422)->assertJsonFragment(['message' => __('app.packages.reasons.expired')]);

        $scheduled = $this->package($owner, $member, ['starts_on' => today()->addDays(5)->toDateString(), 'expires_on' => today()->addDays(20)->toDateString()]);
        $this->book($owner, $room, $member, '09:00', '10:00', $scheduled)->assertStatus(422)->assertJsonFragment(['message' => __('app.packages.reasons.not_started')]);

        $cancelled = $this->package($owner, $member);
        $this->actingAs($owner, 'owner')->post("/member-packages/{$cancelled->id}/cancel")->assertRedirect();
        $this->book($owner, $room, $member, '09:00', '10:00', $cancelled)->assertStatus(422)->assertJsonFragment(['message' => __('app.packages.reasons.cancelled')]);

        $t = PackageTemplate::create(['owner_id' => $owner->id, 'name' => 'Only one room', 'total_minutes' => 600, 'price' => 100, 'validity_days' => 30, 'room_ids' => [$other->id]]);
        $restricted = $this->package($owner, $member, ['package_template_id' => $t->id]);
        $this->book($owner, $room, $member, '09:00', '10:00', $restricted)->assertStatus(422)->assertJsonFragment(['message' => __('app.packages.reasons.room')]);
        $this->book($owner, $other, $member, '09:00', '10:00', $restricted)->assertOk();

        $this->assertSame(1, Booking::where('owner_id', $owner->id)->count());
    }

    public function test_a_package_of_another_member_cannot_be_used(): void
    {
        $owner = $this->owner();
        $ahmed = $this->member($owner);
        $sara = $this->member($owner, 'Sara');
        $room = $this->room($owner);
        $pkg = $this->package($owner, $ahmed);

        $this->book($owner, $room, $sara, '09:00', '10:00', $pkg)->assertNotFound();
        $this->assertSame(0, $pkg->fresh()->used_minutes);
    }

    public function test_with_multiple_packages_only_the_chosen_one_is_charged(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $a = $this->package($owner, $member, ['name' => 'A', 'hours' => 1]);
        $b = $this->package($owner, $member, ['name' => 'B', 'hours' => 10]);

        // A alone can't cover 2h — no combining with B.
        $this->book($owner, $room, $member, '09:00', '11:00', $a)->assertStatus(422);
        $this->book($owner, $room, $member, '09:00', '11:00', $b)->assertOk();

        $this->assertSame(0, $a->fresh()->used_minutes);
        $this->assertSame(120, $b->fresh()->used_minutes);
    }

    // ------------------------------------------------------ edit / cancellation

    public function test_editing_a_booking_reconciles_the_difference(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $pkg = $this->package($owner, $member);

        $this->book($owner, $room, $member, '09:00', '11:00', $pkg)->assertOk();
        $booking = Booking::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame(120, $pkg->fresh()->used_minutes);

        $this->edit($owner, $booking, '09:00', '12:00', $pkg)->assertRedirect("/bookings/{$booking->id}");
        $this->assertSame(180, $pkg->fresh()->used_minutes, '2h → 3h deducts 1h more.');

        $this->edit($owner, $booking->fresh(), '09:00', '10:00', $pkg)->assertRedirect("/bookings/{$booking->id}");
        $this->assertSame(60, $pkg->fresh()->used_minutes, '3h → 1h returns 2h.');

        $adjust = PackageUsage::where('booking_id', $booking->id)->where('action', PackageUsage::BOOKING_ADJUSTMENT)->orderBy('id')->pluck('minutes')->all();
        $this->assertSame([60, -120], $adjust);
        $this->assertEquals(50.0, (float) $booking->fresh()->total_price);
        $this->assertSame(60, app(HourPackageService::class)->heldMinutes($booking, $pkg->id));
    }

    public function test_switching_package_or_back_to_normal_payment_reconciles(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $a = $this->package($owner, $member, ['name' => 'A']);
        $b = $this->package($owner, $member, ['name' => 'B']);

        $this->book($owner, $room, $member, '09:00', '11:00', $a)->assertOk();
        $booking = Booking::where('owner_id', $owner->id)->firstOrFail();

        $this->edit($owner, $booking, '09:00', '11:00', $b)->assertRedirect();
        $this->assertSame(0, $a->fresh()->used_minutes);
        $this->assertSame(120, $b->fresh()->used_minutes);
        $this->assertSame($b->id, $booking->fresh()->member_package_id);

        $this->edit($owner, $booking->fresh(), '09:00', '11:00', null)->assertRedirect();
        $this->assertSame(0, $b->fresh()->used_minutes);
        $fresh = $booking->fresh();
        $this->assertSame('cash', $fresh->payment_method);
        $this->assertNull($fresh->member_package_id);
        $this->assertEquals(200.0, (float) $fresh->total_price, 'Back to normal room pricing.');
        $this->assertEquals(0.0, (float) $fresh->amount_paid, 'The old package value is not carried as a cash deposit.');
    }

    public function test_an_edit_needing_more_hours_than_left_is_refused_and_keeps_the_booking(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $pkg = $this->package($owner, $member, ['hours' => 3]);

        $this->book($owner, $room, $member, '09:00', '11:00', $pkg)->assertOk();
        $booking = Booking::where('owner_id', $owner->id)->firstOrFail();

        $this->edit($owner, $booking, '09:00', '13:00', $pkg)->assertSessionHas('error');
        $this->assertSame(120, $pkg->fresh()->used_minutes);
        $this->assertSame('11:00', substr($booking->fresh()->end_time, 0, 5));
    }

    public function test_cancelling_a_booking_returns_its_hours_and_keeps_history(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $pkg = $this->package($owner, $member);

        $this->book($owner, $room, $member, '09:00', '11:00', $pkg)->assertOk();
        $booking = Booking::where('owner_id', $owner->id)->firstOrFail();

        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/status", ['status' => 'cancelled'])->assertSessionHas('success');
        $this->assertSame(0, $pkg->fresh()->used_minutes);

        $actions = PackageUsage::where('member_package_id', $pkg->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame([PackageUsage::PURCHASE, PackageUsage::BOOKING_USAGE, PackageUsage::BOOKING_CANCELLATION], $actions);

        // A second cancel is an invalid transition and returns nothing more.
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/status", ['status' => 'cancelled'])->assertSessionHas('error');
        $this->assertSame(0, $pkg->fresh()->used_minutes);
        $this->assertSame(3, PackageUsage::where('member_package_id', $pkg->id)->count());
    }

    public function test_cancelling_the_package_keeps_history_and_blocks_further_use(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $pkg = $this->package($owner, $member);
        $this->book($owner, $room, $member, '09:00', '11:00', $pkg)->assertOk();

        $this->actingAs($owner, 'owner')->post("/member-packages/{$pkg->id}/cancel", ['reason' => 'Refunded'])->assertRedirect("/users/{$member->id}#packages");

        $pkg->refresh();
        $this->assertNotNull($pkg->cancelled_at);
        $this->assertSame('Refunded', $pkg->cancelled_reason);
        $this->assertSame(120, $pkg->used_minutes);
        $this->assertSame(Booking::METHOD_PACKAGE, Booking::where('owner_id', $owner->id)->first()->payment_method);
        $this->assertSame(2, PackageUsage::where('member_package_id', $pkg->id)->count());

        $this->expectException(InsufficientPackageBalanceException::class);
        app(HourPackageService::class)->consume($pkg, 10, PackageUsage::BOOKING_USAGE);
    }

    // ------------------------------------------------------------- concurrency

    public function test_the_guarded_update_never_overdraws_even_from_a_stale_model(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $pkg = $this->package($owner, $member, ['hours' => 2]);
        $service = app(HourPackageService::class);

        // Two requests that both loaded the package while it had 2h left.
        $first = MemberPackage::find($pkg->id);
        $second = MemberPackage::find($pkg->id);

        $service->consume($first, 120, PackageUsage::BOOKING_USAGE);

        try {
            $service->consume($second, 120, PackageUsage::BOOKING_USAGE);
            $this->fail('The second claim on the same hours must fail.');
        } catch (InsufficientPackageBalanceException $e) {
            $this->assertSame(0, $e->remainingMinutes);
        }

        $this->assertSame(120, $pkg->fresh()->used_minutes);
        $this->assertSame(1, PackageUsage::where('member_package_id', $pkg->id)->where('action', PackageUsage::BOOKING_USAGE)->count());
    }

    public function test_two_bookings_racing_for_the_last_hours_only_one_wins(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $roomA = $this->room($owner);
        $roomB = $this->room($owner);
        $pkg = $this->package($owner, $member, ['hours' => 2]);

        $this->book($owner, $roomA, $member, '09:00', '11:00', $pkg)->assertOk();
        $this->book($owner, $roomB, $member, '09:00', '11:00', $pkg)->assertStatus(422);

        $this->assertSame(120, $pkg->fresh()->used_minutes);
        $this->assertSame(0, $pkg->fresh()->remainingMinutes());
        $this->assertSame(1, Booking::where('owner_id', $owner->id)->count());
    }

    // ------------------------------------------------------------------ revenue

    public function test_revenue_is_recognised_when_used_and_telescopes_to_the_price(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        // 7h for 100: per-minute value is not a round number.
        $pkg = $this->package($owner, $member, ['hours' => 7, 'price_paid' => 100]);

        foreach ([['09:00', '11:00'], ['11:00', '13:30'], ['13:30', '16:00']] as [$s, $e]) {
            $this->book($owner, $room, $member, $s, $e, $pkg)->assertOk();
        }
        $this->assertSame(0, $pkg->fresh()->remainingMinutes());

        $bookings = Booking::where('owner_id', $owner->id)->get();
        $this->assertEquals(100.0, round($bookings->sum(fn ($b) => (float) $b->total_price), 2), 'Fully used → recognised value sums to exactly the price paid.');
        $this->assertEquals(100.0, round((float) PackageUsage::where('member_package_id', $pkg->id)->sum('value'), 2));

        // Nothing is recognised until the bookings complete.
        $revenue = app(RevenueAnalyticsService::class);
        $period = AnalyticsPeriod::custom(today()->addDay(), today()->addDay());
        $this->assertEquals(0.0, $revenue->bookingRevenue($owner, $period));

        foreach ($bookings as $b) {
            $this->actingAs($owner, 'owner')->post("/bookings/{$b->id}/status", ['status' => 'completed']);
        }
        $this->assertEquals(100.0, round($revenue->bookingRevenue($owner, $period), 2));
    }

    public function test_package_bookings_cannot_take_a_coupon(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $pkg = $this->package($owner, $member);
        Coupon::create(['owner_id' => $owner->id, 'code' => 'TEN', 'discount_type' => 'percentage', 'discount_value' => 10, 'applies_to' => 'both', 'is_active' => true]);

        $this->book($owner, $room, $member, '09:00', '11:00', $pkg)->assertOk();
        $booking = Booking::where('owner_id', $owner->id)->firstOrFail();

        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/coupon", ['code' => 'TEN']);
        $this->assertNull($booking->fresh()->coupon_id);
    }

    // ------------------------------------------------------------ shared sessions

    private function openSession(Owner $owner, Room $room, HotspotUser $member, ?MemberPackage $pkg, int $minutesAgo = 90, int $party = 1): SharedSession
    {
        return SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id, 'party_size' => $party,
            'session_date' => today()->toDateString(), 'start_time' => now()->subMinutes($minutesAgo)->format('H:i'),
            'opened_at' => now()->subMinutes($minutesAgo), 'status' => 'open',
            'billing_unit' => 'minute', 'billed_price_per_hour' => $room->price_per_hour,
            'member_package_id' => $pkg?->id,
        ]);
    }

    public function test_closing_a_session_draws_the_elapsed_minutes_and_covers_the_booking(): void
    {
        Carbon::setTestNow(today()->setTime(14, 0));
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner, 'shared', 60);
        $pkg = $this->package($owner, $member);
        $session = $this->openSession($owner, $room, $member, $pkg, 90);

        $preview = $this->actingAs($owner, 'owner')->getJson("/shared-sessions/{$session->id}/close-preview")->assertOk();
        $this->assertTrue($preview->json('package.covers'));

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk()->assertJson(['success' => true]);

        $this->assertSame(90, $pkg->fresh()->used_minutes);
        $usage = PackageUsage::where('member_package_id', $pkg->id)->where('action', PackageUsage::SESSION_USAGE)->firstOrFail();
        $this->assertSame($session->id, $usage->shared_session_id);

        $booking = Booking::findOrFail($session->fresh()->booking_id);
        $this->assertSame(Booking::METHOD_PACKAGE, $booking->payment_method);
        $this->assertSame('completed', $booking->status);
        $this->assertEquals(75.0, (float) $booking->amount_paid, '1.5h of a 30h/1500 package.');
        $this->assertSame($booking->id, $usage->booking_id);
    }

    public function test_a_session_the_package_cannot_cover_is_billed_normally(): void
    {
        Carbon::setTestNow(today()->setTime(14, 0));
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner, 'shared', 60);
        $pkg = $this->package($owner, $member, ['hours' => 1, 'price_paid' => 50]);
        $session = $this->openSession($owner, $room, $member, $pkg, 90);

        $this->assertFalse($this->actingAs($owner, 'owner')->getJson("/shared-sessions/{$session->id}/close-preview")->json('package.covers'));
        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk();

        $this->assertSame(0, $pkg->fresh()->used_minutes);
        $booking = Booking::findOrFail($session->fresh()->booking_id);
        $this->assertSame('cash', $booking->payment_method);
        $this->assertEquals(90.0, (float) $booking->amount_paid);
    }

    public function test_opening_a_session_with_a_package_requires_party_size_one(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner, 'shared', 60);
        $pkg = $this->package($owner, $member);

        $payload = ['room_id' => $room->id, 'hotspot_user_id' => $member->id, 'session_date' => now()->toDateString(), 'start_time' => now()->format('H:i'), 'member_package_id' => $pkg->id];

        $this->actingAs($owner, 'owner')->post('/shared-sessions', $payload + ['party_size' => 3])
            ->assertSessionHas('error', __('app.packages.reasons.party'));
        $this->assertSame(0, SharedSession::where('owner_id', $owner->id)->count());

        $this->actingAs($owner, 'owner')->post('/shared-sessions', $payload + ['party_size' => 1])->assertRedirect();
        $this->assertSame($pkg->id, SharedSession::where('owner_id', $owner->id)->firstOrFail()->member_package_id);
    }

    public function test_shared_room_advance_bookings_point_to_the_session_instead(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner, 'shared', 60);
        $pkg = $this->package($owner, $member);

        $this->book($owner, $room, $member, '09:00', '11:00', $pkg)->assertStatus(422)
            ->assertJsonFragment(['message' => __('app.packages.reasons.shared_booking')]);
    }

    // ------------------------------------------------------------ package options

    public function test_package_options_explain_eligibility(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $big = $this->package($owner, $member, ['name' => 'Big']);
        $small = $this->package($owner, $member, ['name' => 'Small', 'hours' => 1]);
        $this->package($owner, $member, ['name' => 'Old', 'starts_on' => today()->subDays(10)->toDateString(), 'expires_on' => today()->subDay()->toDateString()]);

        $res = $this->actingAs($owner, 'owner')->getJson('/bookings/package-options?'.http_build_query([
            'hotspot_user_id' => $member->id, 'room_id' => $room->id, 'booking_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00', 'end_time' => '11:00',
        ]))->assertOk();

        $this->assertSame(120, $res->json('minutes'));
        $byName = collect($res->json('packages'))->keyBy('name');
        $this->assertFalse($byName->has('Old'), 'Expired packages are not offered.');
        $this->assertTrue($byName['Big']['eligible']);
        $this->assertFalse($byName['Small']['eligible']);
        $this->assertSame(__('app.packages.reasons.insufficient'), $byName['Small']['reason']);
    }

    // ----------------------------------------------------------- notifications

    public function test_expiring_soon_and_exhausted_notifications_fire_once(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);
        $room = $this->room($owner);
        $this->package($owner, $member, ['name' => 'Soon', 'expires_on' => today()->addDays(2)->toDateString()]);
        $this->package($owner, $member, ['name' => 'Later', 'expires_on' => today()->addDays(20)->toDateString()]);

        $svc = app(NotificationService::class);
        $svc->refreshForOwner($owner);
        $svc->refreshForOwner($owner);
        $this->assertSame(1, Notification::where('owner_id', $owner->id)->where('type', 'package_expiring')->count());

        $pkg = $this->package($owner, $member, ['name' => 'Tiny', 'hours' => 1]);
        $this->book($owner, $room, $member, '09:00', '10:00', $pkg)->assertOk();
        $booking = Booking::where('owner_id', $owner->id)->firstOrFail();
        // Return and use again: still only one "used up" alert.
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/status", ['status' => 'cancelled']);
        $this->book($owner, $room, $member, '10:00', '11:00', $pkg)->assertOk();

        $this->assertSame(1, Notification::where('owner_id', $owner->id)->where('type', 'package_exhausted')->count());
    }

    // --------------------------------------------------- tenancy & permissions

    public function test_other_tenants_templates_packages_and_members_are_404(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $theirMember = $this->member($other);
        $theirPkg = $this->package($other, $theirMember);
        $theirTemplate = PackageTemplate::create(['owner_id' => $other->id, 'name' => 'Theirs', 'total_minutes' => 60, 'price' => 1, 'validity_days' => 1]);

        $this->actingAs($owner, 'owner')->get("/packages/{$theirTemplate->id}/edit")->assertNotFound();
        $this->actingAs($owner, 'owner')->delete("/packages/{$theirTemplate->id}")->assertNotFound();
        $this->actingAs($owner, 'owner')->post("/member-packages/{$theirPkg->id}/cancel")->assertNotFound();
        $this->actingAs($owner, 'owner')->post("/users/{$theirMember->id}/packages", [
            'name' => 'X', 'hours' => 1, 'price_paid' => 1, 'starts_on' => today()->toDateString(), 'expires_on' => today()->toDateString(),
        ])->assertNotFound();

        $mine = $this->member($owner);
        $this->actingAs($owner, 'owner')->post("/users/{$mine->id}/packages", [
            'package_template_id' => $theirTemplate->id, 'name' => 'X', 'hours' => 1, 'price_paid' => 1,
            'starts_on' => today()->toDateString(), 'expires_on' => today()->toDateString(),
        ])->assertSessionHasErrors('package_template_id');

        $this->book($owner, $this->room($owner), $mine, '09:00', '10:00', $theirPkg)->assertNotFound();
        $this->assertNull($theirPkg->fresh()->cancelled_at);
        $this->assertSame(0, $theirPkg->fresh()->used_minutes);
    }

    public function test_staff_permissions_and_feature_gate(): void
    {
        $owner = $this->owner();
        $member = $this->member($owner);

        $makeStaff = function (string $roleKey) use ($owner): Staff {
            $role = Role::whereNull('owner_id')->where('key', $roleKey)->firstOrFail();
            $staff = Staff::create(['owner_id' => $owner->id, 'role_id' => $role->id, 'name' => $roleKey, 'email' => $roleKey.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
            $staff->syncPermissionsFromRole();

            return $staff;
        };
        $payload = ['name' => 'X', 'hours' => 1, 'price_paid' => 10, 'starts_on' => today()->toDateString(), 'expires_on' => today()->toDateString()];

        // Receptionist: can view + assign, can't manage templates.
        $receptionist = $makeStaff('receptionist');
        $this->actingAs($receptionist, 'staff')->get('/packages')->assertOk();
        $this->actingAs($receptionist, 'staff')->get('/packages/create')->assertRedirect();
        $this->actingAs($receptionist, 'staff')->post("/users/{$member->id}/packages", $payload)->assertRedirect("/users/{$member->id}#packages");
        $this->assertSame(1, MemberPackage::where('owner_id', $owner->id)->count());

        // Without packages.assign → denied.
        $receptionist->permissions()->detach(Permission::where('key', 'packages.assign')->value('id'));
        $this->actingAs($receptionist->fresh(), 'staff')->post("/users/{$member->id}/packages", $payload);
        $this->assertSame(1, MemberPackage::where('owner_id', $owner->id)->count());

        // No booking feature → redirected away.
        auth('staff')->logout();
        $noBooking = $this->owner(['workspace']);
        $this->actingAs($noBooking, 'owner')->get('/packages')->assertRedirect('/dashboard');
    }

    public function test_seeded_permissions_exist_via_migration_alone(): void
    {
        DB::table('permissions')->where('key', 'like', 'packages.%')->delete();
        (include database_path('migrations/2026_10_01_000003_seed_hour_package_permissions.php'))->up();
        (include database_path('migrations/2026_10_01_000003_seed_hour_package_permissions.php'))->up();

        $this->assertSame(3, DB::table('permissions')->where('key', 'like', 'packages.%')->count());
        $managerId = DB::table('roles')->whereNull('owner_id')->where('key', 'manager')->value('id');
        $keys = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_id', $managerId)->where('permissions.key', 'like', 'packages.%')->pluck('permissions.key')->all();
        $this->assertEqualsCanonicalizing(['packages.view', 'packages.manage', 'packages.assign'], $keys);
    }
}
