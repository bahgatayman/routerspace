<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeries;
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
use App\Models\Staff;
use App\Models\StaffActivityLog;
use App\Models\Workspace;
use App\Services\HourPackageService;
use App\Services\NotificationService;
use App\Services\RecurringBookingService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recurring bookings — packages, payment arrangement, series money and
 * pricing. Board Room: 100/h, so one Tuesday 10:00–12:00 costs 200 (2 h).
 */
class RecurringBookingMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    private Room $board;

    private Room $hall;     // shared physical room

    private Room $training; // exclusive use of the Hall

    private HotspotUser $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00')); // a Monday

        $plan = Plan::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $this->owner = Owner::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addYear()]);
        foreach ($plan->features as $key) {
            $this->owner->enableFeature($key);
        }
        $ws = Workspace::create(['owner_id' => $this->owner->id, 'name' => 'Main']);
        $this->board = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $ws->id, 'name' => 'Board Room', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 100]);
        $this->hall = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $ws->id, 'name' => 'Hall', 'type' => 'shared', 'capacity' => 6, 'price_per_hour' => 60]);
        $this->training = new Room(['owner_id' => $this->owner->id, 'workspace_id' => $ws->id, 'name' => 'Training Room', 'type' => 'training', 'capacity' => 10, 'price_per_hour' => 100]);
        $this->training->physical_room_id = $this->hall->id;
        $this->training->save();
        $this->member = HotspotUser::create(['owner_id' => $this->owner->id, 'name' => 'Sara', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    /** Weekly on Tuesdays 10–12 in the Board Room, 4 dates, unless overridden. */
    private function payload(array $over = []): array
    {
        return array_merge([
            'room_id' => $this->board->id, 'hotspot_user_id' => $this->member->id,
            'booking_date' => '2026-10-06', 'start_time' => '10:00', 'end_time' => '12:00',
            'repeat_frequency' => 'weekly', 'repeat_weekdays' => [2], 'repeat_end' => 'count', 'repeat_count' => 4,
        ], $over);
    }

    private function review(array $payload)
    {
        return $this->actingAs($this->owner, 'owner')->post(route('bookings.series.review'), $payload);
    }

    /** Review, then confirm every bookable date as reviewed. */
    private function confirm(array $payload)
    {
        $preview = $this->review($payload)->assertOk()->viewData('preview');
        $occ = collect($preview['occurrences'])->map(fn ($o) => ['date' => $o['date'], 'choice' => $o['reason'] === null ? $o['start'].'-'.$o['end'] : 'skip'])->all();
        // As the review page posts it: the dates it showed as covered by the package.
        $covered = collect($preview['occurrences'])->where('covered', true)->pluck('date')->implode(',');

        return $this->actingAs($this->owner, 'owner')->post(route('bookings.series.store'),
            $payload + ['occurrences' => $occ, 'request_token' => (string) Str::uuid(), 'package_dates' => $covered]);
    }

    private function package(float $hours, array $over = []): MemberPackage
    {
        return app(HourPackageService::class)->sell($this->member, array_merge([
            'name' => "{$hours} Hours", 'hours' => $hours, 'price_paid' => 1000,
            'starts_on' => '2026-10-01', 'expires_on' => '2027-03-31',
        ], $over));
    }

    private function series(): BookingSeries
    {
        return BookingSeries::latest('id')->firstOrFail();
    }

    private function dates(?BookingSeries $s = null)
    {
        return Booking::where('booking_series_id', ($s ?? $this->series())->id)->orderBy('booking_date')->get();
    }

    private function money(?BookingSeries $s = null): array
    {
        $s ??= $this->series();

        return app(RecurringBookingService::class)->financials($s, $s->bookings()->get());
    }

    private function cancel(Booking $b)
    {
        return $this->actingAs($this->owner, 'owner')->post("/bookings/{$b->id}/status", ['status' => 'cancelled']);
    }

    private function seriesPay(float $amount, ?string $key = null)
    {
        return $this->actingAs($this->owner, 'owner')->post(route('bookings.series.payment', $this->series()),
            ['amount' => $amount, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    // ------------------------------------------------------------------ packages

    public function test_an_existing_package_is_used_only_when_the_owner_chooses_it(): void
    {
        $pkg = $this->package(20);

        $preview = $this->review($this->payload())->assertOk()->assertSee($pkg->name)->viewData('preview');
        $this->assertNull($preview['package'], 'Listed, never applied on its own.');
        $this->confirm($this->payload())->assertSessionHasNoErrors();
        $this->assertSame(0, $pkg->fresh()->used_minutes);
        $this->assertSame(0, $this->dates()->filter->isPackageCovered()->count());

        $this->confirm($this->payload(['booking_date' => '2026-11-03', 'series_package_id' => $pkg->id]))->assertSessionHasNoErrors();
        $this->assertSame(4, $this->dates()->filter->isPackageCovered()->count());
        $this->assertSame(480, $pkg->fresh()->used_minutes);
        $this->assertSame($pkg->id, $this->series()->member_package_id);
    }

    public function test_package_hours_go_first_and_the_rest_is_charged_at_the_normal_rate(): void
    {
        // 10 dates × 2 h = 20 h needed, 12 h on the package: 6 dates covered, 4 dates (8 h) at 200.
        $pkg = $this->package(12);
        $payload = $this->payload(['repeat_count' => 10, 'series_package_id' => $pkg->id]);

        $preview = $this->review($payload)->viewData('preview');
        $this->assertSame([6, 720, 1200], [$preview['package']['covered_count'], $preview['package']['covered_minutes'], $preview['minutes']]);
        $this->assertSame(800.0, $preview['total']);
        $this->assertSame(range(0, 9), array_keys($preview['occurrences']));
        $this->assertSame([true, true, true, true, true, true, false, false, false, false], array_column($preview['occurrences'], 'covered'), 'Whole dates, in date order.');

        $this->confirm($payload)->assertSessionHas('success');
        $dates = $this->dates();
        $this->assertSame(6, $dates->filter->isPackageCovered()->count());
        $this->assertSame(['unpaid', 'unpaid', 'unpaid', 'unpaid'], $dates->reject->isPackageCovered()->pluck('payment_status')->values()->all());
        $this->assertSame(['200.00', '200.00', '200.00', '200.00'], $dates->reject->isPackageCovered()->pluck('total_price')->values()->all());
        $this->assertSame(720, $pkg->fresh()->used_minutes);
        $this->assertSame(800.0, $this->money()['total']);
    }

    public function test_an_uneven_balance_covers_whole_dates_only_and_keeps_the_rest_on_the_package(): void
    {
        $pkg = $this->package(5); // 2 dates of 2 h fit, 1 h stays on the package
        $this->confirm($this->payload(['series_package_id' => $pkg->id]));

        $this->assertSame(2, $this->dates()->filter->isPackageCovered()->count());
        $this->assertSame(60, $pkg->fresh()->remainingMinutes());
    }

    public function test_a_package_sold_from_the_review_is_used_and_is_never_a_payment_for_the_dates(): void
    {
        $template = PackageTemplate::create(['owner_id' => $this->owner->id, 'name' => '4 Hours', 'total_minutes' => 240, 'price' => 350, 'validity_days' => 60, 'is_active' => true]);

        $response = $this->actingAs($this->owner, 'owner')->post(route('bookings.series.review.package'), $this->payload([
            'sale' => ['package_template_id' => $template->id, 'name' => '4 Hours', 'hours' => 4, 'price_paid' => 350, 'starts_on' => '2026-10-05', 'expires_on' => '2026-12-03'],
        ]))->assertOk();
        $pkg = MemberPackage::sole();
        $this->assertSame([240, '350.00', $template->id], [$pkg->total_minutes, $pkg->price_paid, $pkg->package_template_id]);
        $this->assertSame($pkg->id, $response->viewData('preview')['package']['id'], 'Chosen right away.');
        $this->assertSame(2, $response->viewData('preview')['package']['covered_count']);

        $this->confirm($this->payload(['series_package_id' => $pkg->id]));
        $cash = $this->dates()->reject->isPackageCovered();
        $this->assertSame([0.0, 0.0], $cash->map(fn ($b) => (float) $b->amount_paid)->values()->all(), 'Selling the package paid for none of the other dates.');
        $this->assertSame(['paid' => 0.0, 'remaining' => 400.0], array_intersect_key($this->money(), ['paid' => 1, 'remaining' => 1]));
    }

    public function test_a_bad_package_sale_is_shown_on_the_review_again(): void
    {
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.review.package'), $this->payload(['sale' => ['name' => '', 'hours' => 0]]))
            ->assertOk()->assertViewHas('preview')->assertSee(__('validation.required', ['attribute' => 'name']));
        $this->assertSame(0, MemberPackage::count());
    }

    public function test_a_package_sold_for_a_later_series_starts_with_it_and_one_valid_on_none_of_its_dates_is_refused(): void
    {
        // A series that starts in January (the review's sale form starts the package with the series).
        $payload = $this->payload(['booking_date' => '2027-01-05']);
        $this->review($payload)->assertOk()
            ->assertSee('name="sale[starts_on]" type="date" class="ls-input" value="2027-01-05"', false)
            ->assertSee('name="sale[expires_on]" type="date" class="ls-input" value="2027-02-03"', false);

        // Sold from today for 29 days, it would expire before the first date: refused, nothing sold.
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.review.package'), $payload + [
            'sale' => ['name' => 'Too early', 'hours' => 4, 'price_paid' => 300, 'starts_on' => '2026-10-05', 'expires_on' => '2026-11-03'],
        ])->assertOk()->assertSee(__('app.series.errors.package_outside_dates', ['from' => '5 Jan 2027', 'to' => '26 Jan 2027']));
        $this->assertSame(0, MemberPackage::count(), 'A package that could cover none of the dates is never sold here.');

        // Valid on the dates: sold and chosen.
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.review.package'), $payload + [
            'sale' => ['name' => 'January', 'hours' => 4, 'price_paid' => 300, 'starts_on' => '2027-01-05', 'expires_on' => '2027-02-03'],
        ])->assertOk()->assertViewHas('preview', fn ($p) => $p['package']['covered_count'] === 2);
    }

    public function test_the_chosen_package_stays_listed_even_when_it_cannot_cover_the_dates(): void
    {
        $pkg = $this->package(10, ['expires_on' => '2026-10-20']); // ends before the series starts (27 Oct)
        $this->review($this->payload(['booking_date' => '2026-10-27', 'series_package_id' => $pkg->id]))->assertOk()
            ->assertSee('<option value="'.$pkg->id.'" selected', false)
            ->assertViewHas('preview', fn ($p) => $p['package']['covered_count'] === 0);
    }

    public function test_hours_are_reserved_at_confirmation_used_on_completion_and_released_once_on_cancellation(): void
    {
        $pkg = $this->package(20);
        $this->confirm($this->payload(['series_package_id' => $pkg->id]));
        [$first, $second, $third] = $this->dates()->all();
        $this->assertSame(['reserved' => 480, 'used' => 0], ['reserved' => $this->money()['package_reserved_minutes'], 'used' => $this->money()['package_used_minutes']]);

        $this->actingAs($this->owner, 'owner')->post("/bookings/{$first->id}/status", ['status' => 'completed'])->assertSessionHas('success');
        $this->assertSame([360, 120], [$this->money()['package_reserved_minutes'], $this->money()['package_used_minutes']]);

        $this->cancel($third)->assertSessionHas('success');
        $this->cancel($third); // a retry
        $this->assertSame(360, $pkg->fresh()->used_minutes, 'Returned once: 480 − 120.');
        $this->assertSame(-120, (int) PackageUsage::where('booking_id', $third->id)->where('action', PackageUsage::BOOKING_CANCELLATION)->sum('minutes'));
        $this->assertSame([240, 120], [$this->money()['package_reserved_minutes'], $this->money()['package_used_minutes']]);
        $this->assertSame('confirmed', $second->fresh()->status);
    }

    public function test_the_same_package_hours_are_never_allocated_twice(): void
    {
        $pkg = $this->package(4); // enough for exactly two dates
        // Series A is reviewed while the hours are still there (2 dates promised)…
        $payloadA = $this->payload(['room_id' => $this->training->id, 'series_package_id' => $pkg->id]);
        $preview = $this->review($payloadA)->viewData('preview');
        $this->assertSame(2, $preview['package']['covered_count']);
        // …then series B takes them first.
        $this->confirm($this->payload(['series_package_id' => $pkg->id]));

        $occ = collect($preview['occurrences'])->map(fn ($o) => ['date' => $o['date'], 'choice' => $o['start'].'-'.$o['end']])->all();
        $covered = collect($preview['occurrences'])->where('covered', true)->pluck('date')->implode(',');
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.store'), $payloadA + ['occurrences' => $occ, 'request_token' => (string) Str::uuid(), 'package_dates' => $covered])
            ->assertSessionHas('warning', fn ($m) => str_contains($m, trans_choice('app.series.package_ran_short', 2, ['count' => 2])));

        $this->assertSame(240, $pkg->fresh()->used_minutes);
        $this->assertSame(2, Booking::where('member_package_id', $pkg->id)->count());
        $this->assertSame(240, (int) PackageUsage::where('member_package_id', $pkg->id)->sum('minutes'));
    }

    public function test_a_date_taken_by_another_use_of_the_room_draws_no_package_hours(): void
    {
        $pkg = $this->package(20);
        // One seat of the shared Hall is taken on the second Tuesday: its Training use isn't free then.
        Booking::create(['owner_id' => $this->owner->id, 'room_id' => $this->hall->id, 'hotspot_user_id' => $this->member->id, 'party_size' => 1,
            'booking_date' => '2026-10-13', 'start_time' => '10:00', 'end_time' => '12:00', 'price_per_hour' => 60, 'total_hours' => 2,
            'total_price' => 120, 'amount_paid' => 0, 'payment_status' => 'unpaid', 'status' => 'confirmed']);

        $this->confirm($this->payload(['room_id' => $this->training->id, 'series_package_id' => $pkg->id]));

        $this->assertSame(3, $this->dates()->count());
        $this->assertSame(360, $pkg->fresh()->used_minutes);
    }

    public function test_a_shared_room_series_cannot_use_a_package(): void
    {
        $pkg = $this->package(20);
        $this->review($this->payload(['room_id' => $this->hall->id, 'series_package_id' => $pkg->id]))->assertSessionHasErrors('series_package_id');
    }

    public function test_another_customers_package_cannot_be_used(): void
    {
        $other = HotspotUser::create(['owner_id' => $this->owner->id, 'name' => 'Omar', 'phone' => '011'.rand(10000000, 99999999), 'password' => 'pass1234']);
        $pkg = app(HourPackageService::class)->sell($other, ['name' => 'X', 'hours' => 10, 'price_paid' => 1, 'starts_on' => '2026-10-01', 'expires_on' => '2027-01-01']);

        $this->review($this->payload(['series_package_id' => $pkg->id]))->assertNotFound();
    }

    // ------------------------------------------------------------------ payment arrangement

    public function test_an_upfront_series_starts_unpaid_and_shows_it_everywhere(): void
    {
        $this->confirm($this->payload(['payment_plan' => 'upfront']));
        $series = $this->series();
        $this->assertTrue($series->isUpfront());
        $this->assertSame(['unpaid'], $this->dates()->pluck('payment_status')->unique()->values()->all(), 'Confirming is not a payment.');
        $this->assertSame(['total' => 800.0, 'paid' => 0.0, 'remaining' => 800.0], array_intersect_key($this->money(), ['total' => 1, 'paid' => 1, 'remaining' => 1]));

        $first = $this->dates()->first();
        $this->actingAs($this->owner, 'owner')->get(route('bookings.series.show', $series))->assertOk()
            ->assertSee(__('app.series.upfront_unpaid', ['amount' => 'EGP 800.00']), false);
        $this->actingAs($this->owner, 'owner')->get("/bookings/{$first->id}")->assertSee(__('app.series.unpaid_upfront_booking'));
        $this->actingAs($this->owner, 'owner')->get('/bookings/calendar?view=day&date=2026-10-06')->assertOk()->assertSee(__('app.series.unpaid_tag'));
        Carbon::setTestNow(Carbon::parse('2026-10-06 08:00'));
        $this->actingAs($this->owner, 'owner')->get('/dashboard')->assertOk()->assertSee(__('app.series.unpaid_upfront_title'));
    }

    public function test_one_series_payment_is_spread_over_the_dates_once(): void
    {
        $this->confirm($this->payload(['payment_plan' => 'upfront']));
        $key = (string) Str::uuid();

        $this->seriesPay(300, $key)->assertSessionHas('success');
        $this->seriesPay(300, $key)->assertSessionHas('success', __('app.booking.payment.already_recorded')); // double click
        $this->assertSame([200.0, 100.0, 0.0, 0.0], $this->dates()->map(fn ($b) => (float) $b->amount_paid)->all());
        $this->assertSame(['paid', 'partial', 'unpaid', 'unpaid'], $this->dates()->pluck('payment_status')->all());
        $this->assertSame([300.0, 500.0], [$this->money()['paid'], $this->money()['remaining']]);

        // More than is due: refused, nothing written.
        $this->seriesPay(600)->assertSessionHas('error');
        $this->assertSame(300.0, $this->money()['paid']);

        // The paid date loses its unpaid marker; the others keep it.
        $this->actingAs($this->owner, 'owner')->get("/bookings/{$this->dates()->first()->id}")->assertDontSee(__('app.series.unpaid_upfront_booking'));
        $this->actingAs($this->owner, 'owner')->get("/bookings/{$this->dates()->last()->id}")->assertSee(__('app.series.unpaid_upfront_booking'));
    }

    public function test_per_date_payments_are_tracked_one_by_one(): void
    {
        $this->confirm($this->payload());
        $this->assertSame(BookingSeries::PLAN_PER_SESSION, $this->series()->payment_plan);
        $second = $this->dates()[1];

        $this->actingAs($this->owner, 'owner')->post("/bookings/{$second->id}/payment", ['amount' => 200])->assertSessionHas('success');
        $this->assertSame(['unpaid', 'paid', 'unpaid', 'unpaid'], $this->dates()->pluck('payment_status')->all());
        $this->assertSame([200.0, 600.0], [$this->money()['paid'], $this->money()['remaining']]);
        $this->actingAs($this->owner, 'owner')->get("/bookings/{$this->dates()->first()->id}")->assertDontSee(__('app.series.unpaid_upfront_booking'));
    }

    // ------------------------------------------------------------------ cancellation maths

    public function test_cancelling_lowers_the_total_never_the_payments_and_flags_the_overpayment(): void
    {
        $this->confirm($this->payload(['payment_plan' => 'upfront']));
        $this->seriesPay(300); // first 200 + second 100
        [$first, $second] = $this->dates()->all();

        $this->cancel($second)->assertSessionHas('success');
        $this->cancel($second); // a retry changes nothing
        $m = $this->money();
        $this->assertSame([600.0, 300.0, 400.0, 100.0], [$m['total'], $m['paid'], $m['remaining'], $m['overpaid']]);
        $this->assertSame(100.0, (float) $second->fresh()->amount_paid, 'The payment stays recorded.');
        $this->assertSame($m['total'] - $m['paid'], $m['remaining'] - $m['overpaid']);

        $this->actingAs($this->owner, 'owner')->get(route('bookings.series.show', $this->series()))
            ->assertSee(__('app.series.overpaid', ['amount' => 'EGP 100.00']), false);
    }

    public function test_cancelling_from_a_date_on_stops_the_series_and_recalculates(): void
    {
        $pkg = $this->package(4); // covers the first two dates
        $this->confirm($this->payload(['series_package_id' => $pkg->id, 'payment_plan' => 'upfront']));
        $third = $this->dates()[2];
        $this->actingAs($this->owner, 'owner')->post("/bookings/{$this->dates()[1]->id}/status", ['status' => 'completed']);

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.cancel', $this->series()), ['from_booking_id' => $third->id]);
        $this->assertSame('stopped', $this->series()->status);
        $this->assertSame(['confirmed', 'completed', 'cancelled', 'cancelled'], $this->dates()->pluck('status')->all());
        $this->assertSame(['total' => 0.0, 'remaining' => 0.0], array_intersect_key($this->money(), ['total' => 1, 'remaining' => 1]));
        $this->assertSame(240, $pkg->fresh()->used_minutes, 'The covered dates keep their hours.');
    }

    // ------------------------------------------------------------------ pricing

    public function test_a_room_price_change_never_reprices_the_series_later_batches_included(): void
    {
        $this->confirm($this->payload(['repeat_frequency' => 'daily', 'repeat_count' => 70, 'repeat_weekdays' => []]));
        $this->assertSame('200.00', $this->series()->occurrence_price);

        $this->board->update(['price_per_hour' => 150]);
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00'));
        $this->artisan('bookings:extend-series')->assertSuccessful();

        $this->assertSame(70, $this->dates()->count());
        $this->assertSame(['200.00'], $this->dates()->pluck('total_price')->unique()->values()->all());
    }

    public function test_an_explicit_price_change_shows_its_impact_then_reprices_upcoming_dates_only(): void
    {
        $this->confirm($this->payload());
        [$first, $second, $third, $fourth] = $this->dates()->all();
        $this->actingAs($this->owner, 'owner')->post("/bookings/{$third->id}/payment", ['amount' => 200]); // paid in full
        Carbon::setTestNow(Carbon::parse('2026-10-08 08:00')); // the first date is past

        $impact = $this->actingAs($this->owner, 'owner')->post(route('bookings.series.price.preview', $this->series()), ['price' => 150])
            ->assertOk()->assertSee(__('app.series.price_title'))->viewData('impact');
        $this->assertSame([200.0, 150.0, 2, -100.0], [$impact['old'], $impact['new'], $impact['affected'], $impact['diff']]);
        $this->assertSame([['date' => '2026-10-20', 'reason' => 'paid_more']], $impact['skipped']);
        $this->assertSame([800.0, 700.0], [$impact['before']['total'], $impact['after']['total']]);
        $this->assertSame([200.0, 200.0], [$impact['before']['paid'], $impact['after']['paid']]);
        $this->assertSame([600.0, 500.0], [$impact['before']['remaining'], $impact['after']['remaining']]);
        $this->assertSame('200.00', $second->fresh()->total_price, 'Nothing saved by the preview.');

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.price', $this->series()), ['price' => 150])->assertSessionHas('warning');
        $this->assertSame(['200.00', '150.00', '200.00', '150.00'], $this->dates()->pluck('total_price')->all());
        $this->assertSame('200.00', $third->fresh()->amount_paid);
        $this->assertSame('150.00', $this->series()->occurrence_price);
        $this->assertSame(1, StaffActivityLog::where('action', 'booking.series_price_changed')->count());

        // Applying it again changes nothing.
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.price', $this->series()), ['price' => 150]);
        $this->assertSame(['200.00', '150.00', '200.00', '150.00'], $this->dates()->pluck('total_price')->all());
    }

    public function test_a_price_change_leaves_dates_the_series_package_covers_alone(): void
    {
        $pkg = $this->package(4); // first two dates by package, last two paid normally
        $this->confirm($this->payload(['series_package_id' => $pkg->id]));

        $impact = app(RecurringBookingService::class)->pricePreview($this->series(), 150);
        $this->assertSame([2, 2, []], [$impact['affected'], $impact['package_dates'], $impact['skipped']], 'Not reported as "kept its price".');

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.price', $this->series()), ['price' => 150])->assertSessionHas('success');
        $dates = $this->dates();
        $this->assertSame([true, true, false, false], $dates->map->isPackageCovered()->all());
        $this->assertSame(['150.00', '150.00'], $dates->reject->isPackageCovered()->pluck('total_price')->values()->all());
        $this->assertSame(240, $pkg->fresh()->used_minutes, 'The package dates and their hours are untouched.');
    }

    // ------------------------------------------------------------------ editing

    public function test_one_date_moved_on_its_own_stays_in_its_series(): void
    {
        $this->confirm($this->payload());
        $second = $this->dates()[1];

        $this->actingAs($this->owner, 'owner')->put("/bookings/{$second->id}", [
            'room_id' => $this->board->id, 'hotspot_user_id' => $this->member->id, 'booking_date' => '2026-10-15',
            'start_time' => '14:00', 'end_time' => '16:00', 'payment_choice' => 'none',
        ])->assertSessionHasNoErrors();

        $moved = $second->fresh();
        $this->assertSame(['2026-10-15', $this->series()->id], [$moved->booking_date->toDateString(), $moved->booking_series_id]);
        $this->actingAs($this->owner, 'owner')->get(route('bookings.series.show', $this->series()))->assertSee($moved->booking_date->translatedFormat('D j M Y'));
    }

    public function test_package_dates_moved_to_a_longer_time_draw_more_hours_or_stay_put(): void
    {
        $pkg = $this->package(9); // 4 × 2 h = 8 h held, 1 h left
        $this->confirm($this->payload(['series_package_id' => $pkg->id]));
        $this->assertSame(480, $pkg->fresh()->used_minutes);

        // 10:00–12:30 needs 30 min more per date: only two dates fit in the hour left.
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.update', $this->series()), [
            'from_booking_id' => $this->dates()->first()->id, 'start_time' => '10:00', 'end_time' => '12:30',
        ])->assertSessionHas('warning');

        $this->assertSame(['12:30', '12:30', '12:00', '12:00'], $this->dates()->map(fn ($b) => substr((string) $b->end_time, 0, 5))->all());
        $this->assertSame(540, $pkg->fresh()->used_minutes);
        $this->assertSame(4, $this->dates()->filter->isPackageCovered()->count());
    }

    // ------------------------------------------------------------------ notifications

    public function test_an_unpaid_upfront_series_alerts_once_per_amount_due(): void
    {
        $this->confirm($this->payload(['payment_plan' => 'upfront']));
        $notifications = app(NotificationService::class);
        $alerts = fn () => Notification::where('owner_id', $this->owner->id)->where('type', 'series_upfront_unpaid')->count();

        $notifications->refreshForOwner($this->owner->fresh());
        $notifications->refreshForOwner($this->owner->fresh());
        $this->assertSame(1, $alerts());
        $this->assertSame(0, Notification::where('type', 'series_unpaid')->count(), 'Not the per-date alert as well.');

        $this->seriesPay(200);
        $notifications->refreshForOwner($this->owner->fresh());
        $notifications->refreshForOwner($this->owner->fresh());
        $this->assertSame(2, $alerts(), 'A new amount due: one new alert.');

        $this->seriesPay(600);
        $notifications->refreshForOwner($this->owner->fresh());
        $this->assertSame(2, $alerts(), 'Fully paid: nothing more.');
    }

    // ------------------------------------------------------------------ authorization + UI

    public function test_staff_need_the_package_permission_to_sell_one_here(): void
    {
        $role = Role::whereNull('owner_id')->where('key', 'receptionist')->firstOrFail();
        $staff = Staff::create(['owner_id' => $this->owner->id, 'role_id' => $role->id, 'name' => 'S', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        $staff->syncPermissionsFromRole();
        $staff->permissions()->detach(Permission::where('key', 'packages.assign')->value('id'));

        $this->actingAs($staff, 'staff')->post(route('bookings.series.review'), $this->payload())->assertOk()->assertDontSee(__('app.series.sell_title'));
        $this->actingAs($staff, 'staff')->post(route('bookings.series.review.package'), $this->payload([
            'sale' => ['name' => 'X', 'hours' => 4, 'price_paid' => 1, 'starts_on' => '2026-10-05', 'expires_on' => '2026-12-01'],
        ]))->assertSessionHas('permission_denied');
        $this->assertSame(0, MemberPackage::count());
    }

    public function test_another_owner_cannot_pay_or_reprice_a_series(): void
    {
        $this->confirm($this->payload(['payment_plan' => 'upfront']));
        $plan = Plan::firstOrFail();
        $other = Owner::create(['name' => 'Other', 'email' => 'x'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'X',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addYear()]);
        foreach ($plan->features as $key) {
            $other->enableFeature($key);
        }

        $this->actingAs($other, 'owner')->post(route('bookings.series.payment', $this->series()), ['amount' => 100])->assertNotFound();
        $this->actingAs($other, 'owner')->post(route('bookings.series.price', $this->series()), ['price' => 1])->assertNotFound();
        $this->assertSame(0.0, $this->money()['paid']);
    }

    public function test_review_and_forms_show_the_new_choices_in_both_languages(): void
    {
        $pkg = $this->package(12);
        $this->review($this->payload(['series_package_id' => $pkg->id, 'repeat_count' => 10]))->assertOk()
            ->assertSee('name="payment_plan"', false)
            ->assertSee(__('app.series.plans.upfront'))
            ->assertSee(__('app.series.package_cover', ['hours' => '12h', 'left' => '0m']))
            ->assertSee('20h'); // total time for 10 dates

        $this->actingAs($this->owner, 'owner')->get('/bookings')->assertOk()
            ->assertSee('role="switch"', false)->assertSee('id="qb-start-date-label"', false);

        $this->actingAs($this->owner, 'owner')->withSession(['locale' => 'ar'])->post(route('bookings.series.review'), $this->payload())
            ->assertOk()->assertSee(__('app.series.plans.upfront', [], 'ar'))->assertSee(__('app.series.payment_title', [], 'ar'));
    }
}
