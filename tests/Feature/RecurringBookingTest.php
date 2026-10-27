<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\HotspotUser;
use App\Models\Notification;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomPlan;
use App\Models\StaffActivityLog;
use App\Models\Workspace;
use App\Services\AvailabilityService;
use App\Services\NotificationService;
use App\Services\RecurringBookingService;
use App\Support\Recurrence\RecurrenceRule;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Recurring bookings: a series (repeat rule) whose occurrences are ordinary
 * bookings, each booked through the same lock / capacity / hours / pricing
 * rules as a one-time booking. Concurrency on MySQL: RecurringBookingConcurrencyTest.
 */
class RecurringBookingTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    private Workspace $ws;

    private Room $board;     // ordinary exclusive room, 100/h

    private Room $hall;      // shared physical room, 6 seats, 60/h

    private Room $training;  // another use of the hall, exclusive, 400/h

    private HotspotUser $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->at('2026-10-05 08:00'); // a Monday

        $this->owner = $this->makeOwner();
        $this->ws = Workspace::create(['owner_id' => $this->owner->id, 'name' => 'Main']);
        $this->board = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Board Room', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 100]);
        $this->hall = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Hall', 'type' => 'shared', 'capacity' => 6, 'price_per_hour' => 60]);
        $this->training = new Room(['owner_id' => $this->owner->id, 'workspace_id' => $this->ws->id, 'name' => 'Training Room', 'type' => 'training', 'capacity' => 10, 'price_per_hour' => 400]);
        $this->training->physical_room_id = $this->hall->id;
        $this->training->save();
        $this->member = $this->member();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function at(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when));
    }

    private function makeOwner(): Owner
    {
        $plan = Plan::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addYear()]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function member(?Owner $owner = null): HotspotUser
    {
        return HotspotUser::create(['owner_id' => ($owner ?? $this->owner)->id, 'name' => 'Member '.uniqid(), 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

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
        return $this->actingAs($this->owner, 'owner')->from('/bookings/create')->post(route('bookings.series.review'), $payload);
    }

    /** Review, then confirm every bookable date as reviewed (or per $choose: date => 'skip' | 'HH:MM-HH:MM'). */
    private function confirm(array $payload, array $choose = [], ?string $token = null)
    {
        $preview = $this->review($payload)->assertOk()->viewData('preview');
        $occ = [];
        foreach ($preview['occurrences'] as $o) {
            $choice = $choose[$o['date']] ?? ($o['reason'] === null ? $o['start'].'-'.$o['end'] : 'skip');
            $occ[] = ['date' => $o['date'], 'choice' => $choice];
        }

        return $this->actingAs($this->owner, 'owner')->from('/bookings/create')->post(route('bookings.series.store'),
            $payload + ['occurrences' => $occ, 'request_token' => $token ?? (string) Str::uuid()]);
    }

    private function book(Room $room, string $date, string $start, string $end, string $status = 'confirmed'): Booking
    {
        return Booking::create(['owner_id' => $this->owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $this->member()->id, 'party_size' => 1,
            'booking_date' => $date, 'start_time' => $start, 'end_time' => $end, 'price_per_hour' => 100, 'total_hours' => 2, 'total_price' => 200,
            'amount_paid' => 0, 'payment_status' => 'unpaid', 'status' => $status]);
    }

    private function seriesBookings(?BookingSeries $series = null)
    {
        $series ??= BookingSeries::latest('id')->firstOrFail();

        return Booking::where('booking_series_id', $series->id)->orderBy('booking_date')->get();
    }

    // ------------------------------------------------------------------ recurrence rule

    public function test_daily_rule_and_end_by_count(): void
    {
        $this->assertSame(['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'],
            RecurrenceRule::make('daily', null, '2026-10-06', null, 5)->dates());
    }

    public function test_weekly_rule_on_one_or_several_weekdays_and_end_by_date(): void
    {
        $this->assertSame(['2026-10-06', '2026-10-13', '2026-10-20'], RecurrenceRule::make('weekly', [2], '2026-10-06', null, 3)->dates());
        // Mondays and Wednesdays until the 21st (a start date that isn't one of the days is fine).
        $this->assertSame(['2026-10-07', '2026-10-12', '2026-10-14', '2026-10-19', '2026-10-21'],
            RecurrenceRule::make('weekly', [3, 1], '2026-10-06', '2026-10-21', null)->dates());
        $this->assertSame(['2026-10-06', '2026-10-07', '2026-10-08'], RecurrenceRule::make('daily', null, '2026-10-06', '2026-10-08', null)->dates());
    }

    public function test_dates_cross_month_year_and_daylight_saving_boundaries_without_gaps_or_repeats(): void
    {
        $this->assertSame(['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'], RecurrenceRule::make('daily', null, '2026-12-30', null, 4)->dates());
        // Late October (Egypt's DST change): plain dates, one per day.
        $dates = RecurrenceRule::make('daily', null, '2026-10-28', null, 5)->dates();
        $this->assertSame(['2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01'], $dates);
    }

    public function test_long_series_come_in_batches_that_continue_the_count(): void
    {
        $rule = RecurrenceRule::make('daily', null, '2026-10-06', null, 100);
        $first = $rule->dates();
        $this->assertCount(RecurrenceRule::BATCH, $first);
        $rest = $rule->dates(end($first));
        $this->assertCount(100 - RecurrenceRule::BATCH, $rest);
        $this->assertSame([], $rule->dates(end($rest)));
        $this->assertFalse($rule->hasDatesAfter(end($rest)));
    }

    // ------------------------------------------------------------------ validation

    public function test_invalid_repeat_settings_are_refused_with_the_form_kept(): void
    {
        $cases = [
            'repeat_weekdays' => ['repeat_weekdays' => []],
            'repeat_count' => ['repeat_count' => 1],
            'repeat_until' => ['repeat_end' => 'date', 'repeat_until' => '2026-10-01'],
            'end_time' => ['end_time' => '09:00'],
            'repeat_frequency' => ['repeat_frequency' => 'monthly'],
            'booking_date' => ['booking_date' => '2026-10-01'],
        ];
        foreach ($cases as $field => $over) {
            $this->review($this->payload($over))->assertRedirect('/bookings/create')->assertSessionHasErrors($field);
        }
        // A rule that gives no date at all.
        $this->review($this->payload(['booking_date' => '2026-10-06', 'repeat_end' => 'date', 'repeat_until' => '2026-10-08', 'repeat_weekdays' => [5]]))
            ->assertSessionHasErrors(['repeat_weekdays' => __('app.series.errors.no_dates')]);
        // Custom plans and hour packages don't repeat.
        $this->review($this->payload(['room_plan_id' => 1]))->assertSessionHasErrors(['repeat_frequency' => __('app.series.errors.no_plans')]);
        $this->assertSame(0, BookingSeries::count() + Booking::count());
    }

    // ------------------------------------------------------------------ review + confirm

    public function test_all_dates_available_are_booked_as_ordinary_unpaid_bookings(): void
    {
        $page = $this->review($this->payload())->assertOk()
            ->assertSee(__('app.series.review_title'))->assertSee(__('app.series.not_a_hold'));
        $preview = $page->viewData('preview');
        $this->assertSame(['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27'], array_column($preview['occurrences'], 'date'));
        $this->assertSame([null, null, null, null], array_column($preview['occurrences'], 'reason'));
        $this->assertEquals(800.0, $preview['total']); // 4 × 2h × 100
        $this->assertSame(0, Booking::count(), 'Reviewing books nothing.');

        $this->confirm($this->payload())->assertRedirect()->assertSessionHas('success');

        $series = BookingSeries::firstOrFail();
        $bookings = $this->seriesBookings($series);
        $this->assertCount(4, $bookings);
        foreach ($bookings as $b) {
            $this->assertSame(['confirmed', 'unpaid', '200.00', '0.00'], [$b->status, $b->payment_status, $b->total_price, $b->amount_paid]);
            $this->assertSame([$this->board->id, $this->member->id], [$b->room_id, $b->hotspot_user_id]);
        }
        $this->assertSame(['weekly', [2], 4, 'active'], [$series->frequency, $series->weekdays, $series->occurrence_limit, $series->status]);
    }

    public function test_conflicting_dates_are_shown_with_other_times_and_the_rest_stay_bookable(): void
    {
        $this->book($this->board, '2026-10-13', '10:00', '12:00');
        $this->book($this->board, '2026-10-20', '11:00', '13:00');

        $preview = $this->review($this->payload())->assertOk()->viewData('preview');
        $byDate = collect($preview['occurrences'])->keyBy('date');
        $this->assertNull($byDate['2026-10-06']['reason']);
        $this->assertSame('booked', $byDate['2026-10-13']['reason']);
        $this->assertSame('booked', $byDate['2026-10-20']['reason']);
        $this->assertNotEmpty($byDate['2026-10-13']['suggestions']);
        foreach ($byDate['2026-10-13']['suggestions'] as $s) {
            $this->assertFalse($s['start'] < '12:00' && $s['end'] > '10:00', 'A suggestion never overlaps the existing booking.');
        }
        $this->assertEquals(400.0, $preview['total'], 'Only the bookable dates count.');

        // Skip one conflict, take another time for the other.
        $alt = $byDate['2026-10-20']['suggestions'][0];
        $this->confirm($this->payload(), ['2026-10-20' => $alt['start'].'-'.$alt['end']])->assertSessionHas('success');

        $bookings = $this->seriesBookings();
        $this->assertSame(['2026-10-06', '2026-10-20', '2026-10-27'], $bookings->map(fn ($b) => $b->booking_date->toDateString())->all());
        $this->assertSame(substr($alt['start'], 0, 5), substr($bookings[1]->start_time, 0, 5));
    }

    public function test_a_date_taken_between_review_and_confirm_is_reported_and_the_others_are_booked(): void
    {
        $preview = $this->review($this->payload())->assertOk()->viewData('preview');
        $this->assertNull($preview['occurrences'][1]['reason']);

        // Someone books the 13th meanwhile.
        $this->book($this->board, '2026-10-13', '09:00', '11:00');

        $occ = array_map(fn ($o) => ['date' => $o['date'], 'choice' => $o['start'].'-'.$o['end']], $preview['occurrences']);
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.store'), $this->payload() + ['occurrences' => $occ, 'request_token' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHas('warning');

        $series = BookingSeries::firstOrFail();
        $this->assertSame(['2026-10-06', '2026-10-20', '2026-10-27'], $this->seriesBookings($series)->map(fn ($b) => $b->booking_date->toDateString())->all());
        $this->assertSame([['date' => '2026-10-13', 'start' => '10:00', 'end' => '12:00', 'reason' => 'booked']], $series->unresolved);
        $this->assertSame(1, Booking::where('room_id', $this->board->id)->whereDate('booking_date', '2026-10-13')->count(), 'Never a second, overlapping booking.');

        // Resolved later at another time from the series page.
        $this->actingAs($this->owner, 'owner')->get(route('bookings.series.show', $series))->assertOk()->assertSee(__('app.series.unresolved_title'));
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.occurrences.store', $series), ['date' => '2026-10-13', 'start_time' => '14:00', 'end_time' => '16:00'])
            ->assertSessionHas('success');
        $this->assertNull($series->fresh()->unresolved);
        $this->assertCount(4, $this->seriesBookings($series));
    }

    public function test_uses_of_one_physical_room_never_overlap_through_a_series(): void
    {
        // A Hall (shared use) booking blocks the Training use of the same physical room.
        $this->book($this->hall, '2026-10-13', '10:30', '11:30');

        $preview = $this->review($this->payload(['room_id' => $this->training->id]))->assertOk()->viewData('preview');
        $this->assertSame('booked', collect($preview['occurrences'])->firstWhere('date', '2026-10-13')['reason']);
        $this->confirm($this->payload(['room_id' => $this->training->id]));
        $this->assertCount(3, $this->seriesBookings());

        // And the series' bookings block the Hall in turn.
        $this->assertSame(0, app(AvailabilityService::class)->availabilityForRange($this->hall->fresh(), '2026-10-20', '10:00', '12:00'));
        $this->assertSame(6, app(AvailabilityService::class)->availabilityForRange($this->hall->fresh(), '2026-10-20', '12:00', '13:00'));
    }

    public function test_the_same_review_submitted_twice_creates_one_series(): void
    {
        $token = (string) Str::uuid();
        $this->confirm($this->payload(), [], $token)->assertSessionHas('success');
        $this->confirm($this->payload(), [], $token)->assertRedirect()->assertSessionHas('success', __('app.series.already_created'));

        $this->assertSame(1, BookingSeries::count());
        $this->assertSame(4, Booking::count());
    }

    public function test_two_overlapping_series_never_create_overlapping_bookings(): void
    {
        $this->confirm($this->payload());
        // The second one forces every date through (as a stale review would):
        // the server alone has to refuse them.
        $forced = array_fill_keys(['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27'], '11:00-13:00');
        $this->confirm($this->payload(['start_time' => '11:00', 'end_time' => '13:00', 'hotspot_user_id' => $this->member()->id]), $forced)
            ->assertSessionHasErrors(['occurrences' => trans_choice('app.series.errors.none_booked', 4, ['count' => 4])]);

        $this->assertSame(4, Booking::where('room_id', $this->board->id)->count(), 'The second series could book none of the same Tuesdays.');
        $this->assertSame(1, BookingSeries::count(), 'All four refused: the second series is not kept.');
        $bookings = Booking::where('room_id', $this->board->id)->whereNotIn('status', ['cancelled'])->get();
        foreach ($bookings as $a) {
            foreach ($bookings as $b) {
                if ($a->id !== $b->id && $a->booking_date->eq($b->booking_date)) {
                    $this->assertFalse($a->start_time < $b->end_time && $b->start_time < $a->end_time, 'Overlap found');
                }
            }
        }
    }

    public function test_shared_room_series_respect_seats(): void
    {
        $this->confirm($this->payload(['room_id' => $this->hall->id, 'party_size' => 4]));
        $preview = $this->review($this->payload(['room_id' => $this->hall->id, 'party_size' => 3, 'hotspot_user_id' => $this->member()->id]))->viewData('preview');
        $this->assertSame(['booked', 'booked', 'booked', 'booked'], array_column($preview['occurrences'], 'reason'), 'Only 2 of 6 seats left.');
        $this->assertSame([null, null, null, null], array_column($this->review($this->payload(['room_id' => $this->hall->id, 'party_size' => 2]))->viewData('preview')['occurrences'], 'reason'));
    }

    // ------------------------------------------------------------------ manage

    public function test_one_occurrence_is_edited_or_cancelled_on_its_own(): void
    {
        $this->confirm($this->payload());
        [$first, $second, $third] = $this->seriesBookings()->all();

        $this->actingAs($this->owner, 'owner')->put("/bookings/{$second->id}", [
            'room_id' => $this->board->id, 'hotspot_user_id' => $this->member->id, 'booking_date' => '2026-10-13',
            'start_time' => '14:00', 'end_time' => '15:00', 'payment_choice' => 'none',
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->actingAs($this->owner, 'owner')->post("/bookings/{$third->id}/status", ['status' => 'cancelled'])->assertSessionHas('success');

        $this->assertSame('14:00', substr($second->fresh()->start_time, 0, 5));
        $this->assertSame($second->booking_series_id, $second->fresh()->booking_series_id, 'Still part of the series.');
        $this->assertSame('cancelled', $third->fresh()->status);
        $this->assertSame(['confirmed', '10:00'], [$first->fresh()->status, substr($first->fresh()->start_time, 0, 5)]);
    }

    public function test_this_and_later_occurrences_get_a_new_time_conflicts_keep_theirs(): void
    {
        $this->confirm($this->payload());
        $series = BookingSeries::firstOrFail();
        [$first, $second, $third, $fourth] = $this->seriesBookings($series)->all();
        $this->book($this->board, '2026-10-20', '15:00', '16:00'); // blocks the new time on the 20th

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.update', $series), [
            'from_booking_id' => $second->id, 'start_time' => '14:00', 'end_time' => '17:00',
        ])->assertSessionHas('warning');

        $this->assertSame('10:00', substr($first->fresh()->start_time, 0, 5), 'Earlier occurrence untouched.');
        $this->assertSame(['14:00', '300.00'], [substr($second->fresh()->start_time, 0, 5), $second->fresh()->total_price], 'Moved and re-priced (3h).');
        $this->assertSame('10:00', substr($third->fresh()->start_time, 0, 5), 'Conflict: keeps its old time.');
        $this->assertSame('14:00', substr($fourth->fresh()->start_time, 0, 5));
        $this->assertSame('14:00', substr((string) $series->fresh()->start_time, 0, 5), 'Later dates use the new time.');
    }

    public function test_this_and_later_occurrences_are_cancelled_and_the_series_stops(): void
    {
        $this->confirm($this->payload());
        $series = BookingSeries::firstOrFail();
        [$first, $second, $third, $fourth] = $this->seriesBookings($series)->all();

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.cancel', $series), ['from_booking_id' => $third->id])
            ->assertSessionHas('success');

        $this->assertSame(['confirmed', 'confirmed', 'cancelled', 'cancelled'], [$first->fresh()->status, $second->fresh()->status, $third->fresh()->status, $fourth->fresh()->status]);
        $this->assertSame('stopped', $series->fresh()->status);
    }

    public function test_stopping_keeps_every_booking_and_adds_no_more_dates(): void
    {
        $this->confirm($this->payload(['repeat_frequency' => 'daily', 'repeat_count' => 70, 'repeat_weekdays' => []]));
        $series = BookingSeries::firstOrFail();
        $this->assertCount(RecurrenceRule::BATCH, $this->seriesBookings($series));

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.stop', $series))->assertSessionHas('success');
        $this->assertSame('stopped', $series->fresh()->status);
        $this->assertSame(RecurrenceRule::BATCH, Booking::where('booking_series_id', $series->id)->where('status', 'confirmed')->count(), 'Nothing cancelled.');

        $this->at('2026-11-20 08:00');
        $this->artisan('bookings:extend-series')->assertSuccessful();
        $this->assertCount(RecurrenceRule::BATCH, $this->seriesBookings($series), 'A stopped series gets no more dates.');
    }

    public function test_past_bookings_and_their_prices_never_change(): void
    {
        $this->confirm($this->payload());
        $series = BookingSeries::firstOrFail();
        [$first, $second] = $this->seriesBookings($series)->all();

        $this->board->update(['price_per_hour' => 150]); // price rise after booking
        $this->at('2026-10-14 09:00');                    // the first two dates are past

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.update', $series), [
            'from_booking_id' => $first->id, 'start_time' => '13:00', 'end_time' => '15:00',
        ]);

        $this->assertSame(['10:00', '200.00'], [substr($first->fresh()->start_time, 0, 5), $first->fresh()->total_price]);
        $this->assertSame(['10:00', '200.00'], [substr($second->fresh()->start_time, 0, 5), $second->fresh()->total_price]);
        $later = $this->seriesBookings($series)->last();
        $this->assertSame(['13:00', '200.00'], [substr($later->start_time, 0, 5), $later->total_price], 'A time shift keeps the agreed price.');

        // A new length is a new deal: re-quoted at the room's current price.
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.update', $series), [
            'from_booking_id' => $later->id, 'start_time' => '13:00', 'end_time' => '16:00',
        ]);
        $this->assertSame('450.00', $later->fresh()->total_price);
        $this->assertSame('450.00', $series->fresh()->occurrence_price);
    }

    public function test_another_owner_can_neither_see_nor_change_a_series(): void
    {
        $this->confirm($this->payload());
        $series = BookingSeries::firstOrFail();
        $b = $this->seriesBookings($series)->first();
        $other = $this->makeOwner();

        $this->actingAs($other, 'owner')->get(route('bookings.series.show', $series))->assertNotFound();
        $this->actingAs($other, 'owner')->post(route('bookings.series.stop', $series))->assertNotFound();
        $this->actingAs($other, 'owner')->post(route('bookings.series.future.cancel', $series), ['from_booking_id' => $b->id])->assertNotFound();
        $this->actingAs($other, 'owner')->post(route('bookings.series.future.update', $series), ['from_booking_id' => $b->id, 'start_time' => '13:00', 'end_time' => '14:00'])->assertNotFound();
        $this->actingAs($other, 'owner')->get(route('bookings.series.index'))->assertOk()->assertDontSee($this->member->name);
        // Nor book another owner's room or member into a series.
        $this->actingAs($other, 'owner')->post(route('bookings.series.review'), $this->payload())->assertNotFound();
        $this->assertSame(['active', 'confirmed'], [$series->fresh()->status, $b->fresh()->status]);
    }

    // ------------------------------------------------------------------ payments + notifications

    public function test_an_unpaid_date_stays_a_valid_booking_and_does_not_stop_the_series(): void
    {
        $this->confirm($this->payload());
        $series = BookingSeries::firstOrFail();
        $first = $this->seriesBookings($series)->first();

        $this->actingAs($this->owner, 'owner')->post("/bookings/{$first->id}/payment", ['amount' => 200])->assertSessionHas('success');
        $this->assertSame('paid', $first->fresh()->payment_status);
        $this->assertSame(['unpaid', 'unpaid', 'unpaid'], $this->seriesBookings($series)->slice(1)->pluck('payment_status')->values()->all());
        $this->assertSame('active', $series->fresh()->status);
    }

    public function test_owner_notifications_are_summaries_without_duplicates(): void
    {
        $this->book($this->board, '2026-10-13', '10:00', '12:00');
        $this->confirm($this->payload(), ['2026-10-13' => 'skip']);
        $series = BookingSeries::firstOrFail();
        $this->assertSame(1, Notification::where('owner_id', $this->owner->id)->where('type', 'series_created')->count());

        $service = app(NotificationService::class);
        $service->refreshForOwner($this->owner->fresh());
        $service->refreshForOwner($this->owner->fresh());
        $unpaid = Notification::where('owner_id', $this->owner->id)->where('type', 'series_unpaid')->get();
        $this->assertCount(1, $unpaid, 'One summary per series, not one per date, and not again on refresh.');
        $this->assertStringContainsString("/bookings/series/{$series->id}", $unpaid->first()->action_url);

        // A later change affecting booked dates: one attention summary, no duplicates.
        $this->board->update(['is_available' => false]);
        $service->refreshForOwner($this->owner->fresh());
        $service->refreshForOwner($this->owner->fresh());
        $this->assertSame(1, Notification::where('owner_id', $this->owner->id)->where('type', 'series_attention')->count());
        $this->actingAs($this->owner, 'owner')->get(route('bookings.series.show', $series))->assertSee(__('app.series.needs_attention'));
        $this->assertSame(3, Booking::where('booking_series_id', $series->id)->where('status', 'confirmed')->count(), 'Nothing cancelled automatically.');
    }

    public function test_the_migrations_create_everything_a_series_needs(): void
    {
        // "no such table: booking_series" on confirm = 2026_10_30_000001 not run.
        $this->assertTrue(Schema::hasTable('booking_series'));
        $this->assertTrue(Schema::hasColumns('booking_series', [
            'owner_id', 'room_id', 'hotspot_user_id', 'frequency', 'weekdays', 'starts_on', 'ends_on', 'occurrence_limit',
            'start_time', 'end_time', 'price_per_hour', 'occurrence_price', 'payment_plan', 'member_package_id',
            'status', 'generated_through', 'unresolved', 'request_token',
        ]));
        $this->assertTrue(Schema::hasColumn('bookings', 'booking_series_id'));
        // Series payments are once-only through the idempotency keys.
        $this->assertTrue(Schema::hasTable('idempotency_keys'));
        $this->assertTrue(collect(Schema::getIndexes('booking_series'))->contains(fn ($i) => $i['unique'] && $i['columns'] === ['request_token']),
            'The same review can never create two series.');
    }

    public function test_owner_pages_keep_working_before_the_migration_is_applied(): void
    {
        // The layout refreshes notifications on every owner page: without the
        // booking_series table (not migrated yet) that must not fail.
        Schema::table('bookings', fn ($t) => $t->dropConstrainedForeignId('booking_series_id'));
        Schema::drop('booking_series');

        app(NotificationService::class)->refreshForOwner($this->owner->fresh());
        $this->actingAs($this->owner, 'owner')->get('/bookings')->assertOk();
    }

    // ------------------------------------------------------------------ batches

    public function test_long_series_add_later_dates_in_batches_and_report_conflicts(): void
    {
        $this->confirm($this->payload(['repeat_frequency' => 'daily', 'repeat_count' => 70, 'repeat_weekdays' => []]));
        $series = BookingSeries::firstOrFail();
        $this->assertCount(60, $this->seriesBookings($series));
        $this->assertSame('2026-12-04', $series->generated_through->toDateString());

        $this->book($this->board, '2026-12-07', '10:00', '11:00'); // conflicts with a later date
        $this->at('2026-11-01 08:00');
        $this->artisan('bookings:extend-series')->assertSuccessful();

        $series->refresh();
        $this->assertCount(69, $this->seriesBookings($series));
        $this->assertSame('2026-12-14', $series->generated_through->toDateString());
        $this->assertSame('2026-12-07', $series->unresolved[0]['date']);
        $this->assertSame(1, Notification::where('type', 'series_extended')->count());

        $this->artisan('bookings:extend-series')->assertSuccessful();
        $this->assertCount(69, $this->seriesBookings($series), 'Idempotent: nothing more to add.');
    }

    // ------------------------------------------------------------------ pre-deployment review regressions

    public function test_an_interrupted_extension_is_redone_without_duplicate_dates_or_notifications(): void
    {
        // Shared room with free seats: only the series' own once-per-date rule stops a second copy.
        $this->confirm($this->payload(['room_id' => $this->hall->id, 'party_size' => 1, 'repeat_frequency' => 'daily', 'repeat_count' => 70, 'repeat_weekdays' => []]));
        $series = BookingSeries::firstOrFail();
        $this->at('2026-11-01 08:00');
        $this->artisan('bookings:extend-series')->assertSuccessful();
        $this->assertCount(70, $this->seriesBookings($series));

        // The run died after booking but before saving its progress: the next run redoes the batch.
        $this->assertSame('2026-12-14', $series->fresh()->generated_through->toDateString());
        BookingSeries::whereKey($series->id)->update(['generated_through' => '2026-12-04']);
        $this->artisan('bookings:extend-series')->assertSuccessful();
        $this->artisan('bookings:extend-series')->assertSuccessful();

        $this->assertCount(70, $this->seriesBookings($series), 'No date booked twice.');
        $this->assertSame(70, $this->seriesBookings($series)->pluck('booking_date')->map->toDateString()->unique()->count());
        $this->assertSame(1, Notification::where('type', 'series_extended')->count(), 'A retried batch does not notify again.');
        $this->assertEmpty($series->fresh()->unresolved ?? [], 'Dates it had already booked are not reported as conflicts.');
    }

    public function test_a_series_stopped_after_the_command_loaded_it_gets_no_more_dates(): void
    {
        $this->confirm($this->payload(['repeat_frequency' => 'daily', 'repeat_count' => 70, 'repeat_weekdays' => []]));
        $loaded = BookingSeries::firstOrFail();               // what the command's chunk holds
        BookingSeries::whereKey($loaded->id)->update(['status' => BookingSeries::STOPPED]); // stopped meanwhile
        $this->at('2026-11-01 08:00');

        $this->assertSame(['created' => 0, 'failed' => 0], app(RecurringBookingService::class)->extend($loaded));
        $this->assertCount(RecurrenceRule::BATCH, $this->seriesBookings($loaded));
    }

    public function test_one_failing_series_does_not_hold_up_the_others(): void
    {
        $this->confirm($this->payload(['repeat_frequency' => 'daily', 'repeat_count' => 70, 'repeat_weekdays' => []]));
        $this->confirm($this->payload(['room_id' => $this->hall->id, 'repeat_frequency' => 'daily', 'repeat_count' => 70, 'repeat_weekdays' => []]));
        [$broken, $healthy] = BookingSeries::orderBy('id')->get()->all();
        $this->at('2026-11-01 08:00');

        $real = app(RecurringBookingService::class);
        $this->instance(RecurringBookingService::class, \Mockery::mock(RecurringBookingService::class, function ($m) use ($real, $broken) {
            $m->shouldReceive('extend')->andReturnUsing(fn (BookingSeries $s) => $s->id === $broken->id ? throw new \RuntimeException('boom') : $real->extend($s));
        }));

        $this->artisan('bookings:extend-series')->assertFailed();
        $this->assertCount(70, $this->seriesBookings($healthy), 'The other series still got its dates.');
        $this->assertSame('2026-12-04', $broken->fresh()->generated_through->toDateString(), 'The broken one is retried next run.');
    }

    public function test_stopping_withdraws_unbooked_dates_and_no_date_can_be_added_afterwards(): void
    {
        $this->book($this->board, '2026-10-13', '10:00', '12:00');
        $this->confirm($this->payload(), ['2026-10-13' => '10:00-12:00']); // forced: fails, kept as unresolved
        $series = BookingSeries::firstOrFail();
        $this->assertSame('2026-10-13', $series->unresolved[0]['date']);

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.stop', $series));
        $this->assertNull($series->fresh()->unresolved);
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.occurrences.store', $series), ['date' => '2026-10-13', 'start_time' => '14:00', 'end_time' => '16:00'])
            ->assertSessionHas('error', __('app.series.reasons.stopped'));
        $this->assertCount(3, $this->seriesBookings($series));
        $this->assertSame(3, Booking::where('booking_series_id', $series->id)->where('status', 'confirmed')->count(), 'Stopping cancels nothing.');
    }

    public function test_a_date_already_booked_in_the_series_is_never_booked_twice(): void
    {
        $this->confirm($this->payload(['room_id' => $this->hall->id, 'party_size' => 1]));
        $series = BookingSeries::firstOrFail();

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.occurrences.store', $series), ['date' => '2026-10-13', 'start_time' => '14:00', 'end_time' => '16:00'])
            ->assertSessionHas('error', __('app.series.reasons.exists'));
        $this->assertCount(4, $this->seriesBookings($series));

        // A date cancelled on its own can be booked again.
        $this->seriesBookings($series)->firstWhere(fn ($b) => $b->booking_date->toDateString() === '2026-10-13')->update(['status' => 'cancelled']);
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.occurrences.store', $series), ['date' => '2026-10-13', 'start_time' => '14:00', 'end_time' => '16:00'])
            ->assertSessionHas('success');
    }

    public function test_a_database_error_on_one_date_is_kept_for_the_owner_and_the_rest_are_booked(): void
    {
        if (\DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uses an SQLite trigger to fail one insert.');
        }
        \DB::statement("CREATE TRIGGER fail_one BEFORE INSERT ON bookings WHEN NEW.booking_date LIKE '2026-10-13%' BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

        $this->confirm($this->payload())->assertSessionHas('warning');
        $series = BookingSeries::firstOrFail();
        $this->assertCount(3, $this->seriesBookings($series), 'The other dates are booked.');
        $this->assertSame([['date' => '2026-10-13', 'start' => '10:00', 'end' => '12:00', 'reason' => 'error']], $series->fresh()->unresolved);

        // The owner can book it at its own time once the database is fine again.
        \DB::statement('DROP TRIGGER fail_one');
        $this->actingAs($this->owner, 'owner')->get(route('bookings.series.show', $series))->assertOk()
            ->assertSee(__('app.series.book_time', ['time' => '10:00–12:00']));
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.occurrences.store', $series), ['date' => '2026-10-13', 'start_time' => '10:00', 'end_time' => '12:00'])
            ->assertSessionHas('success');
        $this->assertCount(4, $this->seriesBookings($series));
        $this->assertNull($series->fresh()->unresolved);
    }

    public function test_a_series_change_never_reprices_an_occurrence_with_its_own_plan(): void
    {
        $this->confirm($this->payload());
        $series = BookingSeries::firstOrFail();
        [$first, $second] = $this->seriesBookings($series)->all();
        // Changed on its own to a Custom Plan through the normal booking edit.
        $plan = RoomPlan::create(['owner_id' => $this->owner->id, 'room_id' => $this->board->id, 'name' => 'Pack', 'people' => 1,
            'duration_minutes' => 120, 'is_full_day' => false, 'price' => 150, 'sort_order' => 0]);
        $second->update(['room_plan_id' => $plan->id, 'total_price' => 150]);

        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.update', $series), [
            'from_booking_id' => $first->id, 'start_time' => '13:00', 'end_time' => '15:00',
        ])->assertSessionHas('warning');

        $this->assertSame(['10:00', '150.00'], [substr($second->fresh()->start_time, 0, 5), $second->fresh()->total_price], 'Kept as it was.');
        $this->assertSame('13:00', substr($first->fresh()->start_time, 0, 5));
    }

    public function test_series_actions_leave_the_same_audit_entries_as_one_time_bookings(): void
    {
        $this->confirm($this->payload());
        $series = BookingSeries::firstOrFail();
        $log = fn (string $action) => StaffActivityLog::where('owner_id', $this->owner->id)->where('action', $action)->count();
        $this->assertSame(4, $log('booking.created'));

        $first = $this->seriesBookings($series)->first();
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.update', $series), ['from_booking_id' => $first->id, 'start_time' => '13:00', 'end_time' => '15:00']);
        $this->assertSame(4, $log('booking.updated'));
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.cancel', $series), ['from_booking_id' => $first->id]);
        $this->assertSame(4, $log('booking.cancelled'));
    }

    public function test_the_series_page_shows_statuses_and_times_in_arabic(): void
    {
        $this->confirm($this->payload());
        $series = BookingSeries::firstOrFail();

        $this->actingAs($this->owner, 'owner')->withSession(['locale' => 'ar'])->get(route('bookings.series.show', $series))->assertOk()
            ->assertSee(__('app.series.booking_status.confirmed', [], 'ar'))
            ->assertSee('10:00 – 12:00')
            // (the layout's quick-booking modal has its own 12-hour slot list)
            ->assertDontSee($this->seriesBookings($series)->first()->timeRange(), false);
    }

    // ------------------------------------------------------------------ capacity parity with one-time bookings

    /** One-time booking through BookingController::store(); true when it was created. */
    private function storeOnce(Room $room, string $date, array $extra = []): bool
    {
        $before = Booking::whereNull('booking_series_id')->count();
        $this->actingAs($this->owner, 'owner')->from('/bookings/create')->post('/bookings', array_merge([
            'room_id' => $room->id, 'hotspot_user_id' => $this->member->id, 'booking_date' => $date,
            'start_time' => '10:00', 'end_time' => '12:00', 'payment_choice' => 'none',
        ], $extra));

        return Booking::whereNull('booking_series_id')->count() === $before + 1;
    }

    /** Seats taken in a room on a date (party_size as given), outside any series. */
    private function hold(Room $room, string $date, int $seats): void
    {
        $this->book($room, $date, '10:00', '12:00')->update(['party_size' => $seats]);
    }

    public function test_an_exclusive_room_is_one_seat_with_guests_in_both_flows(): void
    {
        // Headcount as guests: both book the whole room as one seat.
        $this->assertTrue($this->storeOnce($this->board, '2026-10-08', ['guest_count' => 5]));
        $this->assertSame([1, 5], [(int) Booking::firstOrFail()->party_size, (int) Booking::firstOrFail()->guest_count]);
        $this->confirm($this->payload(['guest_count' => 5, 'repeat_count' => 2]))->assertSessionHasNoErrors();
        $this->assertSame([[1, 5], [1, 5]], $this->seriesBookings()->map(fn ($b) => [(int) $b->party_size, (int) $b->guest_count])->all());

        // A party size above 1 never fits a capacity of 1: both refuse it, nothing is stored.
        $this->assertFalse($this->storeOnce($this->board, '2026-10-09', ['party_size' => 3]));
        $this->review($this->payload(['booking_date' => '2026-10-20', 'party_size' => 3]))->assertSessionHasErrors('party_size');
        $series = BookingSeries::firstOrFail();
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.future.update', $series), [
            'from_booking_id' => $this->seriesBookings($series)->first()->id, 'start_time' => '13:00', 'end_time' => '15:00', 'party_size' => 3,
        ])->assertSessionHasErrors('party_size');
        $this->assertSame(1, BookingSeries::count());
        $this->assertSame(0, Booking::where('party_size', '>', 1)->count());
    }

    public function test_shared_room_seats_are_counted_the_same_in_both_flows(): void
    {
        // 6 seats, 5 taken on both dates.
        foreach (['2026-10-08', '2026-10-06'] as $date) {
            $this->hold($this->hall, $date, 5);
        }

        $this->assertFalse($this->storeOnce($this->hall, '2026-10-08', ['party_size' => 2]), 'One-time: 2 seats don’t fit.');
        $preview = $this->review($this->payload(['room_id' => $this->hall->id, 'party_size' => 2, 'repeat_count' => 2]))->viewData('preview');
        $this->assertSame('booked', $preview['occurrences'][0]['reason'], 'Series: 2 seats don’t fit either.');

        $this->assertTrue($this->storeOnce($this->hall, '2026-10-08', ['party_size' => 1]), 'One-time: the last seat fits.');
        $preview = $this->review($this->payload(['room_id' => $this->hall->id, 'party_size' => 1, 'repeat_count' => 2]))->viewData('preview');
        $this->assertNull($preview['occurrences'][0]['reason'], 'Series: the last seat fits too.');
    }

    public function test_another_use_of_the_physical_room_blocks_both_flows(): void
    {
        // One seat of the shared Hall taken: the Training use of the same room is not free.
        foreach (['2026-10-08', '2026-10-06'] as $date) {
            $this->hold($this->hall, $date, 1);
        }

        $this->assertFalse($this->storeOnce($this->training, '2026-10-08'));
        $preview = $this->review($this->payload(['room_id' => $this->training->id, 'repeat_count' => 2]))->viewData('preview');
        $this->assertSame('booked', $preview['occurrences'][0]['reason']);
        $this->assertNull($preview['occurrences'][1]['reason'], 'The next Tuesday is free.');
    }

    // ------------------------------------------------------------------ quick-booking modal

    public function test_the_quick_booking_modal_offers_repeat_and_reaches_the_same_review(): void
    {
        // Every "New Booking" link opens the modal (layouts.app), so Repeat must be there too.
        $this->actingAs($this->owner, 'owner')->get('/bookings')->assertOk()
            ->assertSee('id="qb-repeat-toggle"', false)
            ->assertSee(__('app.series.repeat'));
        // The full form keeps its own copy: one of each id, no clash with the modal's.
        $page = $this->actingAs($this->owner, 'owner')->get('/bookings/create')->assertOk()->getContent();
        $this->assertSame(1, substr_count($page, 'id="repeat-toggle"'));
        $this->assertSame(1, substr_count($page, 'id="qb-repeat-toggle"'));

        // Exactly what the modal posts (guest_count, no party_size / payment fields).
        $this->actingAs($this->owner, 'owner')->post(route('bookings.series.review'), [
            'from_quick' => '1', 'repeat' => '1', 'hotspot_user_id' => $this->member->id, 'room_id' => $this->board->id,
            'booking_date' => '2026-10-06', 'start_time' => '10:00', 'end_time' => '12:00', 'guest_count' => 1,
            'repeat_frequency' => 'weekly', 'repeat_weekdays' => [2], 'repeat_end' => 'count', 'repeat_count' => 4, 'repeat_until' => '',
        ])->assertOk()->assertViewHas('preview', fn ($p) => count($p['occurrences']) === 4);
    }

    public function test_a_problem_with_a_repeat_from_the_modal_is_shown_on_the_full_form_filled_in(): void
    {
        $this->actingAs($this->owner, 'owner')->from('/bookings')->post(route('bookings.series.review'), [
            'from_quick' => '1', 'repeat' => '1', 'hotspot_user_id' => $this->member->id, 'room_id' => $this->board->id,
            'booking_date' => '2026-10-06', 'start_time' => '10:00', 'end_time' => '12:00', 'guest_count' => 1,
            'repeat_frequency' => 'weekly', 'repeat_end' => 'date', 'repeat_count' => 4, 'repeat_until' => '',
        ])->assertRedirect('/bookings/create')->assertSessionHasErrors(['repeat_weekdays', 'repeat_until'])->assertSessionHasInput('repeat', '1');

        // Without from_quick the full form's own behaviour is unchanged (back where it came from).
        $this->actingAs($this->owner, 'owner')->from('/bookings/create?x=1')->post(route('bookings.series.review'), $this->payload(['repeat_weekdays' => []]))
            ->assertRedirect('/bookings/create?x=1');
    }

    // ------------------------------------------------------------------ pattern, first date, payment visibility, price copy

    public function test_weekly_days_are_kept_and_the_review_names_the_first_date(): void
    {
        // Starts on Friday 9 Oct, repeats on Mondays: the first date is Monday 12 Oct.
        $response = $this->review($this->payload(['booking_date' => '2026-10-09', 'repeat_weekdays' => [1], 'repeat_count' => 3]))->assertOk();

        $this->assertSame(['2026-10-12', '2026-10-19', '2026-10-26'], array_column($response->viewData('preview')['occurrences'], 'date'));
        $response->assertSee(__('app.series.first_date'))
            ->assertSee(Carbon::parse('2026-10-12')->translatedFormat('D j M Y'))
            ->assertSee(__('app.series.summary_shift', ['date' => Carbon::parse('2026-10-12')->translatedFormat('D j M Y')]))
            ->assertSee('name="repeat_weekdays[]" value="1"', false); // carried into the confirm form
    }

    public function test_when_the_start_date_is_a_chosen_day_it_is_the_first_date(): void
    {
        $this->review($this->payload(['repeat_weekdays' => [2, 4]]))->assertOk()
            ->assertViewHas('preview', fn ($p) => array_column($p['occurrences'], 'date') === ['2026-10-06', '2026-10-08', '2026-10-13', '2026-10-15'])
            ->assertDontSee(__('app.series.summary_shift', ['date' => Carbon::parse('2026-10-06')->translatedFormat('D j M Y')]));
    }

    public function test_a_daily_series_ignores_weekdays_and_starts_on_its_start_date(): void
    {
        // Weekdays sent along with "daily" (a stale field) change nothing.
        $this->review($this->payload(['booking_date' => '2026-10-09', 'repeat_frequency' => 'daily', 'repeat_weekdays' => [1], 'repeat_count' => 3]))->assertOk()
            ->assertViewHas('preview', fn ($p) => array_column($p['occurrences'], 'date') === ['2026-10-09', '2026-10-10', '2026-10-11'])
            ->assertSee(__('app.series.every_day'));
    }

    public function test_the_review_reads_in_order_and_long_series_never_hide_a_conflict(): void
    {
        // 20 daily dates, one of them taken: the list shows the first few plus every conflict.
        $this->book($this->board, '2026-10-20', '10:00', '12:00');
        $page = $this->review($this->payload(['repeat_frequency' => 'daily', 'repeat_weekdays' => [], 'repeat_count' => 20, 'payment_plan' => 'upfront']))
            ->assertOk()->getContent();

        // recap → numbers → dates → payment plan → footer
        $at = fn (string $needle) => strpos($page, $needle);
        $order = [$at('class="ls-series-recap"'), $at('class="ls-series-metrics"'), $at('class="ls-series-dates"'), $at('class="ls-series-pay-plan"'), $at('class="ls-series-foot"')];
        $this->assertNotContains(false, $order);
        $this->assertSame($order, collect($order)->sort()->values()->all(), 'Recap, numbers, dates, payment, footer — in that order.');

        // Compact list: available dates after the 6th wait behind "show all"; the conflict (15th date) is listed anyway.
        $this->assertSame(13, substr_count($page, 'data-extra hidden'));
        $this->assertMatchesRegularExpression('/class="ls-series-date is-conflict"[^>]*>\s*<input type="hidden" name="occurrences\[14\]\[date\]" value="2026-10-20">/', $page);
        $this->assertStringContainsString(trans_choice('app.series.show_all', 20, ['count' => 20]), $page);
        $this->assertStringContainsString(trans_choice('app.series.conflicts_count', 1, ['count' => 1]), $page);

        $this->assertMatchesRegularExpression('/name="payment_plan" value="upfront"\s+checked/', $page);
        $this->assertStringContainsString('name="payment_plan" value="per_session"', $page);
        $this->assertStringContainsString(__('app.series.to_pay.upfront'), $page);

        // Opened again from the address bar: back to the form, not a 404.
        $this->actingAs($this->owner, 'owner')->get('/bookings/series/review')->assertRedirect('/bookings/create');
    }

    public function test_the_modal_gets_step_two_as_a_fragment_and_problems_as_json(): void
    {
        $fragment = ['X-LS-Fragment' => 'series-review'];
        $response = $this->actingAs($this->owner, 'owner')->withHeaders($fragment)
            ->post(route('bookings.series.review'), $this->payload(['from_quick' => '1', 'payment_plan' => 'upfront']))->assertOk();

        $html = $response->getContent();
        $this->assertStringStartsWith('<div class="ls-series-review"', ltrim($html), 'Just the step, no page around it.');
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringContainsString('data-series-review', $html);
        $this->assertMatchesRegularExpression('/name="payment_plan" value="upfront"\s+checked/', $html);
        $this->assertSame(0, Booking::count(), 'Opening step 2 books nothing.');

        // A problem goes back to step 1 as a message, not a redirect to another page.
        $this->actingAs($this->owner, 'owner')->withHeaders($fragment)
            ->post(route('bookings.series.review'), $this->payload(['from_quick' => '1', 'repeat_weekdays' => [], 'repeat_end' => 'date', 'repeat_until' => '']))
            ->assertStatus(422)->assertJsonStructure(['message', 'errors']);
    }

    public function test_a_confirm_that_can_book_no_date_keeps_nothing_and_says_so(): void
    {
        // Every date is taken after the review (or the confirm is forged): each one is still requested.
        $dates = ['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27'];
        foreach ($dates as $d) {
            $this->book($this->board, $d, '10:00', '12:00');
        }
        $forced = array_fill_keys($dates, '10:00-12:00');
        $token = (string) Str::uuid();

        $this->confirm($this->payload(), $forced, $token)->assertRedirect('/bookings/create')
            ->assertSessionHasErrors(['occurrences' => trans_choice('app.series.errors.none_booked', 4, ['count' => 4])])
            ->assertSessionHasInput('room_id', $this->board->id);
        // …and the form's repeat section shows that message (the quick modal's copy never does).
        $message = trans_choice('app.series.errors.none_booked', 4, ['count' => 4]);
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['occurrences' => [$message]]));
        $this->view('bookings._repeat-fields', ['rp' => 'repeat', 'useOld' => true, 'errors' => $errors])->assertSee($message);
        $this->view('bookings._repeat-fields', ['rp' => 'qb-repeat', 'useOld' => false, 'errors' => $errors])->assertDontSee($message);
        $this->assertSame(0, BookingSeries::count(), 'No empty series is left to collect later batches.');
        $this->assertSame(4, Booking::count(), 'Each date still has exactly its one booking.');
        $this->assertSame(0, Notification::where('owner_id', $this->owner->id)->where('type', 'series_created')->count());

        // The token wasn't used up: once the dates are free again, the same confirm makes the series.
        Booking::query()->delete();
        $this->confirm($this->payload(), $forced, $token)->assertSessionHas('success');
        $this->assertCount(4, $this->seriesBookings());

        // One date booked is still a series, with the others listed for the owner (unchanged).
        BookingSeries::query()->delete();
        Booking::query()->delete();
        foreach (array_slice($dates, 1) as $d) {
            $this->book($this->board, $d, '10:00', '12:00');
        }
        $this->confirm($this->payload(), $forced)->assertSessionHas('warning');
        $series = BookingSeries::sole();
        $this->assertCount(1, $this->seriesBookings($series));
        $this->assertSame(array_slice($dates, 1), array_column($series->unresolved, 'date'));
    }

    public function test_repeat_problems_are_explained_in_the_owners_language(): void
    {
        $fragment = ['X-LS-Fragment' => 'series-review'];
        $count = __('app.series.errors.count_range', ['max' => RecurrenceRule::MAX_COUNT], 'ar');
        $this->actingAs($this->owner, 'owner')->withSession(['locale' => 'ar'])->withHeaders($fragment)
            ->post(route('bookings.series.review'), $this->payload(['from_quick' => '1', 'repeat_count' => 1]))
            ->assertStatus(422)->assertJsonPath('errors.repeat_count.0', $count);

        $this->actingAs($this->owner, 'owner')->withSession(['locale' => 'en'])->withHeaders($fragment)
            ->post(route('bookings.series.review'), $this->payload(['from_quick' => '1', 'repeat_end' => 'date', 'repeat_count' => null, 'repeat_until' => '2026-10-01']))
            ->assertStatus(422)->assertJsonPath('errors.repeat_until.0', __('app.series.errors.until_after', [], 'en'));
        $this->assertSame(0, BookingSeries::count());
    }

    public function test_price_copy_says_the_price_is_agreed_not_the_rooms_current_price(): void
    {
        $this->review($this->payload())->assertOk()
            ->assertSee(__('app.series.price_note'))
            ->assertSee(__('app.series.per_date_price'))
            ->assertDontSee('Prices are worked out per date with the room’s current pricing.');

        // The quick modal: one date's price is labelled as such, with a series estimate.
        $this->actingAs($this->owner, 'owner')->get('/bookings')->assertOk()
            ->assertSee('id="qb-repeat-summary"', false)
            ->assertSee('id="qb-total-label"', false)
            ->assertSee(__('app.series.start_date'))
            ->assertSee(json_encode(__('app.series.per_date_price'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
    }

    public function test_the_review_reads_right_in_arabic(): void
    {
        $this->actingAs($this->owner, 'owner')->withSession(['locale' => 'ar'])
            ->post(route('bookings.series.review'), $this->payload(['booking_date' => '2026-10-09', 'repeat_weekdays' => [1]]))->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('app.series.first_date', [], 'ar'))
            ->assertSee(__('app.series.price_note', [], 'ar'))
            ->assertSee(__('app.series.plans.upfront', [], 'ar'))
            ->assertDontSee('يُحسب السعر لكل موعد حسب تسعير الغرفة الحالي');
    }

    // ------------------------------------------------------------------ regression + UI

    public function test_one_time_bookings_and_the_pages_still_work(): void
    {
        $this->actingAs($this->owner, 'owner')->get('/bookings/create')->assertOk()
            ->assertSee(__('app.series.repeat'))->assertSee('formaction="'.route('bookings.series.review').'"', false);
        $this->actingAs($this->owner, 'owner')->post('/bookings', [
            'room_id' => $this->board->id, 'hotspot_user_id' => $this->member->id, 'booking_date' => '2026-10-08',
            'start_time' => '10:00', 'end_time' => '11:00', 'payment_choice' => 'none',
        ])->assertSessionHasNoErrors();
        $single = Booking::firstOrFail();
        $this->assertNull($single->booking_series_id);
        $this->assertSame(0, BookingSeries::count());

        $this->confirm($this->payload());
        $occurrence = $this->seriesBookings()->first();
        $this->actingAs($this->owner, 'owner')->get("/bookings/{$occurrence->id}")->assertOk()->assertSee(__('app.series.manage'));
        $this->actingAs($this->owner, 'owner')->get("/bookings/{$single->id}")->assertOk()->assertDontSee(__('app.series.manage'));
        $this->actingAs($this->owner, 'owner')->get('/bookings/calendar?view=day&date=2026-10-06')->assertOk()->assertSee(__('app.series.marker'));
        $this->actingAs($this->owner, 'owner')->get(route('bookings.series.index'))->assertOk()->assertSee($this->member->name)->assertSee(__('app.series.every_weekdays', ['days' => __('app.series.weekdays_short.2')]));
    }
}
