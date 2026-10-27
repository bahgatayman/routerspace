<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidIdempotencyKeyException;
use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\PackageTemplate;
use App\Models\Room;
use App\Services\ActivityLogger;
use App\Services\HourPackageService;
use App\Services\RecurringBookingService;
use App\Services\RoomPricingService;
use App\Support\Money;
use App\Support\Recurrence\RecurrenceRule;
use App\Support\RequestIdempotency;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Recurring bookings (owner / staff side): review a repeating schedule built
 * from the booking form, confirm it, and manage the series afterwards. All
 * booking rules live in RecurringBookingService, which books every
 * occurrence through the same checks as a one-time booking.
 */
class RecurringBookingController extends Controller
{
    public function __construct(private RecurringBookingService $series) {}

    /** The booking form's fields plus the repeat rule. */
    private function draftRules(): array
    {
        return [
            'room_id' => 'required|integer',
            'hotspot_user_id' => 'required|integer',
            'booking_date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'party_size' => 'nullable|integer|min:1|max:999',
            'guest_count' => 'nullable|integer|min:1|max:999',
            'room_pricing_profile_id' => 'nullable|integer',
            'room_plan_id' => 'nullable|integer',
            // Chosen on the review step — never the single-booking form's
            // package field (hidden while repeating), never automatic.
            'series_package_id' => 'nullable|integer',
            'payment_plan' => 'nullable|in:'.implode(',', BookingSeries::PLANS),
            'notes' => 'nullable|string|max:500',
            'repeat_frequency' => 'required|in:'.implode(',', RecurrenceRule::FREQUENCIES),
            'repeat_weekdays' => 'required_if:repeat_frequency,weekly|array',
            'repeat_weekdays.*' => 'integer|between:0,6',
            'repeat_end' => 'required|in:count,date',
            'repeat_count' => 'required_if:repeat_end,count|nullable|integer|min:2|max:'.RecurrenceRule::MAX_COUNT,
            'repeat_until' => 'required_if:repeat_end,date|nullable|date|after:booking_date',
        ];
    }

    /**
     * Validate + owner-scope everything a series books. Throws a
     * ValidationException (back to the form, input kept) when it can't be one.
     *
     * @return array{0: array, 1: array} [validated, draft]
     */
    private function draftFrom(Request $request, RoomPricingService $pricing): array
    {
        // The repeat fields' own messages (in the owner's language; the generic validation strings are English-only).
        $v = $request->validate($this->draftRules(), [
            'repeat_count.required_if' => __('app.series.errors.count_range', ['max' => RecurrenceRule::MAX_COUNT]),
            'repeat_count.integer' => __('app.series.errors.count_range', ['max' => RecurrenceRule::MAX_COUNT]),
            'repeat_count.min' => __('app.series.errors.count_range', ['max' => RecurrenceRule::MAX_COUNT]),
            'repeat_count.max' => __('app.series.errors.count_range', ['max' => RecurrenceRule::MAX_COUNT]),
            'repeat_weekdays.required_if' => __('app.series.errors.no_dates'),
            'repeat_until.required_if' => __('app.series.errors.until_after'),
            'repeat_until.after' => __('app.series.errors.until_after'),
        ]);
        $owner = TenantContext::user();

        // A Custom Plan prices a single visit; a series is priced per date
        // with the room's standard pricing (or a profile), optionally covered
        // by an hour package chosen on the review step.
        if (! empty($v['room_plan_id'])) {
            throw ValidationException::withMessages(['repeat_frequency' => __('app.series.errors.no_plans')]);
        }

        $room = Room::where('id', $v['room_id'])->where('owner_id', $owner->id)->firstOrFail();
        if (! $room->is_available) {
            throw ValidationException::withMessages(['room_id' => __('app.booking.room_disabled')]);
        }
        $this->assertWholeRoomParty($room, $v['party_size'] ?? null);
        $member = HotspotUser::where('id', $v['hotspot_user_id'])->where('owner_id', $owner->id)->firstOrFail();
        try {
            $profile = $pricing->resolveProfile($room, (int) ($v['room_pricing_profile_id'] ?? 0) ?: null);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['room_pricing_profile_id' => __('app.pricing_profiles.errors.not_available')]);
        }

        $v['booking_date'] = Carbon::parse($v['booking_date'])->toDateString();
        $byCount = $v['repeat_end'] === 'count';
        $rule = RecurrenceRule::make(
            $v['repeat_frequency'],
            $v['repeat_weekdays'] ?? [],
            $v['booking_date'],
            $byCount ? null : Carbon::parse($v['repeat_until'])->toDateString(),
            $byCount ? (int) $v['repeat_count'] : null,
        );
        if ($rule->dates(null, 1) === []) {
            throw ValidationException::withMessages(['repeat_weekdays' => __('app.series.errors.no_dates')]);
        }

        // The customer's own, still usable package — packages never cover shared-room bookings.
        $package = null;
        if (! empty($v['series_package_id'])) {
            $package = MemberPackage::where('owner_id', $owner->id)->where('hotspot_user_id', $member->id)
                ->whereNull('cancelled_at')->findOrFail($v['series_package_id']);
            if ($room->isShared()) {
                throw ValidationException::withMessages(['series_package_id' => __('app.packages.reasons.shared_booking')]);
            }
        }

        return [$v, RecurringBookingService::draft($owner, $room, $member, $profile, $rule, $v, $package)];
    }

    /** The review page: dates, money, payment plan, package choice (and a package sale form). */
    private function reviewView(array $v, array $draft): View
    {
        $owner = TenantContext::user();
        $staff = auth('staff')->user();

        // Step 2 of the quick-booking modal asks for the review alone (no page around it).
        return view(request()->header('X-LS-Fragment') === 'series-review' ? 'bookings.series._review' : 'bookings.series.review', [
            'v' => $v,
            'draft' => $draft,
            'preview' => $this->series->preview($draft),
            'requestToken' => (string) Str::uuid(),
            // The customer's packages that are still usable (expiry per date is checked per date).
            // The chosen package is always listed, so a choice never silently disappears.
            'packages' => $draft['room']->isShared() ? collect() : MemberPackage::where('owner_id', $owner->id)
                ->where('hotspot_user_id', $draft['member']->id)->whereNull('cancelled_at')
                ->where(fn ($q) => $q->whereDate('expires_on', '>=', $draft['rule']->startsOn->toDateString())
                    ->when($draft['package'], fn ($q2, $chosen) => $q2->orWhere('id', $chosen->id)))
                ->orderBy('expires_on')->get(),
            'templates' => PackageTemplate::where('owner_id', $owner->id)->where('is_active', true)->orderBy('name')->get(),
            // Same permission as selling one on the member profile.
            'canSell' => ! $draft['room']->isShared() && (! $staff || $staff->hasPermission('packages.assign')),
        ]);
    }

    /** Step 1: every date of the first batch, priced, with conflicts and other times. */
    public function review(Request $request, RoomPricingService $pricing): View|JsonResponse
    {
        try {
            [$v, $draft] = $this->draftFrom($request, $pricing);
        } catch (ValidationException $e) {
            // Step 2 of the modal: the problem is shown on step 1, nothing lost.
            if ($request->header('X-LS-Fragment') === 'series-review') {
                return $this->fragmentError($e);
            }
            // From the modal without script: the full booking form shows them, filled in, repeat on.
            throw $request->boolean('from_quick') ? $e->redirectTo('/bookings/create') : $e;
        }

        return $this->reviewView($v, $draft);
    }

    private function fragmentError(ValidationException $e): JsonResponse
    {
        return response()->json(['message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
    }

    /**
     * Sell the customer a package from the review step (the same sale as on
     * the member profile: HourPackageService::saleRules()/sell(), templates
     * included), then show the review again with it chosen. The package's
     * own price is what was received for the package — it is never a payment
     * on any of these dates.
     */
    public function sellPackage(Request $request, RoomPricingService $pricing, HourPackageService $packages, ActivityLogger $activityLogger): View|JsonResponse
    {
        try {
            [$v, $draft] = $this->draftFrom($request, $pricing);
            if ($draft['room']->isShared()) {
                throw ValidationException::withMessages(['series_package_id' => __('app.packages.reasons.shared_booking')]);
            }
        } catch (ValidationException $e) {
            if ($request->header('X-LS-Fragment') === 'series-review') {
                return $this->fragmentError($e);
            }
            throw $e;
        }
        $ownerId = TenantContext::id();
        // The review is a POST-only page: a sale problem is shown on it again
        // (its own errors, what was typed kept) rather than redirected "back".
        $validator = Validator::make((array) $request->input('sale', []), HourPackageService::saleRules($ownerId));
        $template = ! empty($request->input('sale.package_template_id'))
            ? PackageTemplate::where('owner_id', $ownerId)->find($request->input('sale.package_template_id'))
            : null;
        if ($template && ! $template->is_active) {
            $validator->after(fn ($val) => $val->errors()->add('package_template_id', __('app.packages.errors.template_inactive')));
        }
        // Sold here to cover this series: a package valid on none of its dates
        // would be sold and then cover nothing — refused, never sold silently.
        $dates = $draft['rule']->dates(null, RecurrenceRule::BATCH);
        $validator->after(function ($val) use ($dates) {
            $data = $val->getData();
            if ($dates === [] || empty($data['starts_on']) || empty($data['expires_on']) || $val->errors()->hasAny(['starts_on', 'expires_on'])) {
                return;
            }
            $from = Carbon::parse($data['starts_on'])->toDateString();
            $until = Carbon::parse($data['expires_on'])->toDateString();
            if ($until < $dates[0] || $from > end($dates)) {
                $val->errors()->add('expires_on', __('app.series.errors.package_outside_dates', [
                    'from' => Carbon::parse($dates[0])->translatedFormat('j M Y'), 'to' => Carbon::parse(end($dates))->translatedFormat('j M Y'),
                ]));
            }
        });
        if ($validator->fails()) {
            $errors = new MessageBag(collect($validator->errors()->messages())->mapWithKeys(fn ($m, $k) => ['sale.'.$k => $m])->all());

            return $this->reviewView($v, $draft)->withErrors($errors);
        }
        $sale = $validator->validated();

        $pkg = $packages->sell($draft['member'], $sale, $template);
        $activityLogger->log('package.assigned', $draft['member'], "Assigned hour package \"{$pkg->name}\" to {$draft['member']->name}");

        $draft['package'] = $pkg;
        $v['series_package_id'] = $pkg->id;
        session()->now('success', __('app.packages.assigned', ['name' => $pkg->name]));

        return $this->reviewView($v, $draft);
    }

    /** Step 2: book the chosen dates (each re-checked under the room lock). */
    public function store(Request $request, RoomPricingService $pricing): RedirectResponse
    {
        [$v, $draft] = $this->draftFrom($request, $pricing);
        $request->validate([
            'occurrences' => 'required|array|max:'.RecurrenceRule::BATCH,
            'occurrences.*.date' => 'required|date_format:Y-m-d',
            'occurrences.*.choice' => 'required|string|max:20',
            'request_token' => 'required|uuid',
        ]);

        // This review was already confirmed (double click, back + resubmit):
        // answer with that series, never a second one. A true race between two
        // identical submits is caught by the unique token below.
        if ($existing = BookingSeries::where('owner_id', TenantContext::id())->where('request_token', $request->input('request_token'))->first()) {
            return redirect()->route('bookings.series.show', $existing)->with('success', __('app.series.already_created'));
        }

        // Only dates of the reviewed batch, one booking per date, at the
        // reviewed time or another time that day.
        $batch = array_flip($draft['rule']->dates(null, RecurrenceRule::BATCH));
        $choices = [];
        foreach ($request->input('occurrences') as $o) {
            if ($o['choice'] === 'skip') {
                continue;
            }
            if (! isset($batch[$o['date']]) || ! preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $o['choice'], $m) || $m[2] <= $m[1]) {
                throw ValidationException::withMessages(['occurrences' => __('app.series.errors.invalid_choice')]);
            }
            $choices[$o['date']] = ['date' => $o['date'], 'start' => $m[1], 'end' => $m[2]];
        }
        if ($choices === []) {
            return redirect('/bookings/create')->withInput($request->except('occurrences', 'request_token'))
                ->withErrors(['occurrences' => __('app.series.errors.nothing_selected')]);
        }

        // Dates the review showed as covered by the package (only used to say so if that changed).
        $reviewedCovered = $draft['package'] ? array_values(array_intersect(
            explode(',', (string) $request->input('package_dates', '')), array_keys($choices))) : [];

        try {
            $result = $this->series->create($draft, array_values($choices), $request->input('request_token'), $reviewedCovered);
        } catch (UniqueConstraintViolationException) {
            // The same review submitted twice: the first one made the series
            // (or, when it could book nothing, kept nothing — see below).
            $existing = BookingSeries::where('owner_id', TenantContext::id())->where('request_token', $request->input('request_token'))->first();
            if ($existing) {
                return redirect()->route('bookings.series.show', $existing)->with('success', __('app.series.already_created'));
            }
            $result = ['series' => null, 'failed' => $choices];
        }

        if ($result['series'] === null) {
            return redirect('/bookings/create')->withInput($request->except('occurrences', 'request_token'))
                ->withErrors(['occurrences' => trans_choice('app.series.errors.none_booked', count($result['failed']), ['count' => count($result['failed'])])]);
        }

        $flash = trans_choice('app.series.created', count($result['created']), ['count' => count($result['created'])]);
        if ($result['failed']) {
            $flash .= ' '.trans_choice('app.series.created_failed', count($result['failed']), ['count' => count($result['failed'])]);
        }
        if ($result['uncovered']) {
            // The package ran short at confirmation: said, never silent.
            $flash .= ' '.trans_choice('app.series.package_ran_short', $result['uncovered'], ['count' => $result['uncovered']]);
        }

        return redirect()->route('bookings.series.show', $result['series'])->with($result['failed'] || $result['uncovered'] ? 'warning' : 'success', $flash);
    }

    /** A payment for the whole series (upfront plan), recorded on its dates earliest first. */
    public function recordPayment(Request $request, int $id): RedirectResponse
    {
        $series = $this->find($id);
        $v = $request->validate(['amount' => 'required|numeric|gt:0|max:9999999.99', 'idempotency_key' => 'nullable|string|max:100']);
        try {
            $key = RequestIdempotency::keyFrom($request);
        } catch (InvalidIdempotencyKeyException) {
            return back()->with('error', __('app.booking.idempotency_invalid'));
        }

        $result = $this->series->recordSeriesPayment($series, (float) $v['amount'], $key);

        return match ($result['status']) {
            'recorded' => back()->with('success', __('app.series.payment_recorded', ['count' => count($result['applied'])])),
            'replayed' => back()->with('success', __('app.booking.payment.already_recorded')),
            'conflict' => back()->with('error', __('app.booking.payment.idempotency_mismatch')),
            default => back()->with('error', __('app.series.payment_exceeds', ['amount' => Money::format($result['remaining'])])),
        };
    }

    /** Step 1 of a price change: what it would do (no change yet). */
    public function pricePreview(Request $request, int $id): View
    {
        $series = $this->find($id)->load(['room', 'hotspotUser']);
        $price = (float) $request->validate(['price' => 'required|numeric|min:0|max:9999999.99'])['price'];

        return view('bookings.series.price', ['series' => $series, 'impact' => $this->series->pricePreview($series, $price)]);
    }

    /** Step 2: apply it to the upcoming dates that can take it. */
    public function changePrice(Request $request, int $id): RedirectResponse
    {
        $series = $this->find($id);
        $price = (float) $request->validate(['price' => 'required|numeric|min:0|max:9999999.99'])['price'];
        $result = $this->series->changePrice($series, $price);

        $message = trans_choice('app.series.price_changed', $result['updated'], ['count' => $result['updated']]);
        if ($result['skipped']) {
            $message .= ' '.trans_choice('app.series.updated_failed', count($result['skipped']), [
                'count' => count($result['skipped']),
                'dates' => collect($result['skipped'])->map(fn ($f) => Carbon::parse($f['date'])->translatedFormat('j M').' ('.__('app.series.reasons.'.$f['reason']).')')->implode(', '),
            ]);
        }

        return redirect()->route('bookings.series.show', $series)->with($result['skipped'] ? 'warning' : 'success', $message);
    }

    public function index(): View
    {
        $today = today()->toDateString();
        $upcoming = fn ($q) => $q->whereDate('booking_date', '>=', $today)->whereIn('status', ['pending', 'confirmed']);

        $series = BookingSeries::where('owner_id', TenantContext::id())
            ->with(['room.workspace', 'hotspotUser'])
            ->withCount(['bookings as upcoming_count' => $upcoming])
            ->withMin(['bookings as next_date' => $upcoming], 'booking_date')
            ->withCount(['bookings as unpaid_count' => fn ($q) => $upcoming($q)->where('payment_status', Booking::PAYMENT_UNPAID)])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(20);

        return view('bookings.series.index', compact('series'));
    }

    public function show(int $id): View
    {
        $series = $this->find($id)->load(['room.workspace', 'hotspotUser', 'pricingProfile']);
        $bookings = $series->bookings()->with('room')->get();
        $attention = $this->series->attention($series, $bookings);

        // Other times for each date that couldn't be booked (first ten).
        $draft = $this->series->draftOf($series);
        // A date that did get booked (e.g. by a request interrupted before it
        // could clear its list) is not offered again.
        $bookedDates = $bookings->where('status', '!=', 'cancelled')->map(fn ($b) => $b->booking_date->toDateString())->flip();
        $unresolved = collect($series->unresolved ?? [])
            ->filter(fn ($u) => $u['date'] >= today()->toDateString() && ! isset($bookedDates[$u['date']]))
            ->take(10)
            // A date that only failed to save may still be free at its own time.
            ->map(fn ($u) => $u + ['suggestions' => $this->series->suggestions($draft, $u['date'], $u['start'], $u['end'],
                includeRequested: $u['reason'] === RecurringBookingService::ERROR)])
            ->values();

        $upcoming = $bookings->filter(fn ($b) => in_array($b->status, ['pending', 'confirmed'], true) && $b->booking_date->gte(today()))->values();
        $money = $this->series->financials($series, $bookings);
        $series->loadMissing('memberPackage');

        return view('bookings.series.show', compact('series', 'bookings', 'attention', 'unresolved', 'upcoming', 'money'));
    }

    /** Book one more date (e.g. another time for a date that couldn't be booked). */
    public function addOccurrence(Request $request, int $id): RedirectResponse
    {
        $series = $this->find($id);
        $v = $request->validate([
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        [$booking, $reason] = $this->series->bookOccurrence($series, $v['date'], $v['start_time'], $v['end_time']);
        if (! $booking) {
            return back()->with('error', __('app.series.reasons.'.$reason));
        }
        $this->series->dismiss($series, $v['date']);

        return back()->with('success', __('app.series.occurrence_added', ['date' => $booking->booking_date->translatedFormat('D j M'), 'time' => $booking->timeRange()]));
    }

    public function dismiss(Request $request, int $id): RedirectResponse
    {
        $series = $this->find($id);
        $date = $request->validate(['date' => 'required|date_format:Y-m-d'])['date'];
        $this->series->dismiss($series, $date);

        return back()->with('success', __('app.series.dismissed'));
    }

    /** This occurrence and every later one: new time / party. */
    public function updateFuture(Request $request, int $id): RedirectResponse
    {
        $series = $this->find($id);
        $v = $request->validate([
            'from_booking_id' => 'required|integer',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'party_size' => 'nullable|integer|min:1|max:999',
            'guest_count' => 'nullable|integer|min:1|max:999',
        ]);
        $from = $this->occurrence($series, (int) $v['from_booking_id']);
        $this->assertWholeRoomParty($series->room, $v['party_size'] ?? null);

        $result = $this->series->updateFuture($series, $from, $v['start_time'], $v['end_time'],
            (int) ($v['party_size'] ?? $series->party_size), isset($v['guest_count']) ? (int) $v['guest_count'] : $series->guest_count);

        $message = trans_choice('app.series.updated', count($result['updated']), ['count' => count($result['updated'])]);
        if ($result['failed']) {
            $message .= ' '.trans_choice('app.series.updated_failed', count($result['failed']), [
                'count' => count($result['failed']),
                'dates' => collect($result['failed'])->map(fn ($f) => Carbon::parse($f['date'])->translatedFormat('j M').' ('.__('app.series.reasons.'.$f['reason']).')')->implode(', '),
            ]);
        }

        return back()->with($result['failed'] ? 'warning' : 'success', $message);
    }

    /** This occurrence and every later one: cancelled (normal cancellation), series stopped. */
    public function cancelFuture(Request $request, int $id): RedirectResponse
    {
        $series = $this->find($id);
        $from = $this->occurrence($series, (int) $request->validate(['from_booking_id' => 'required|integer'])['from_booking_id']);
        $count = $this->series->cancelFuture($series, $from);

        return back()->with('success', trans_choice('app.series.cancelled', $count, ['count' => $count]));
    }

    public function stop(int $id): RedirectResponse
    {
        $series = $this->find($id);
        if ($series->isActive()) {
            $this->series->stop($series);
        }

        return back()->with('success', __('app.series.stopped'));
    }

    /**
     * An exclusive room is booked whole: it takes one seat (party_size 1,
     * Room::effectiveCapacity()) and its headcount travels as guest_count.
     * BookingController::store() refuses a larger party_size for one (it never
     * fits a capacity of 1); a series refuses it up front the same way,
     * instead of reviewing the dates as free and failing each one on confirm.
     */
    private function assertWholeRoomParty(?Room $room, $partySize): void
    {
        if ($room && ! $room->isShared() && (int) ($partySize ?? 1) > 1) {
            throw ValidationException::withMessages(['party_size' => __('app.series.errors.whole_room_party')]);
        }
    }

    private function find(int $id): BookingSeries
    {
        return BookingSeries::where('owner_id', TenantContext::id())->findOrFail($id);
    }

    private function occurrence(BookingSeries $series, int $bookingId): Booking
    {
        return Booking::where('owner_id', $series->owner_id)->where('booking_series_id', $series->id)->findOrFail($bookingId);
    }
}
