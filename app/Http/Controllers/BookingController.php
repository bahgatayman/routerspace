<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingCapacityExceededException;
use App\Exceptions\CouponRejectedException;
use App\Exceptions\CouponUsageLimitExceededException;
use App\Exceptions\InsufficientPackageBalanceException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\SharedSessionCapacityExceededException;
use App\Http\Controllers\Concerns\GeneratesTimeSlots;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\PackageUsage;
use App\Models\Product;
use App\Models\Room;
use App\Models\RoomPlan;
use App\Models\RoomPricingProfile;
use App\Models\SaleItem;
use App\Models\SharedSession;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\BusinessHoursService;
use App\Services\CouponService;
use App\Services\HourPackageService;
use App\Services\InventoryService;
use App\Services\RoomPricingService;
use App\Services\SalesService;
use App\Support\Duration;
use App\Support\IdempotencyKey;
use App\Support\Money;
use App\Support\Pricing\PriceQuote;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    use GeneratesTimeSlots;

    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): Response
    {
        $ownerId = TenantContext::id();
        $status = $request->get('status');
        $date = $request->get('date');
        $roomId = $request->get('room_id');

        $bookings = Booking::where('owner_id', $ownerId)
            ->with(['room.workspace', 'hotspotUser'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($date, fn ($q) => $q->whereDate('booking_date', $date))
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->orderBy('booking_date', 'desc')
            ->orderBy('start_time', 'asc')
            ->paginate(15);

        $rooms = Room::where('owner_id', $ownerId)->get();

        return Inertia::render('Bookings/Index', [
            'bookings' => $bookings->withQueryString()->through(fn (Booking $b) => [
                'id' => $b->id,
                'date_label' => $b->booking_date->format('M d, Y'),
                'time_range' => $b->timeRange(),
                'customer_id' => $b->hotspotUser?->id,
                'customer_name' => $b->hotspotUser?->name,
                'party_size' => (int) $b->party_size,
                'workspace' => $b->room?->workspace?->name,
                'room' => $b->room?->name,
                'total_hours' => $b->total_hours,
                'total_price' => (float) $b->total_price,
                'status' => $b->status,
                'status_label' => $b->statusLabel(),
                'status_class' => $b->statusBadgeClass(),
                'can_edit' => in_array($b->status, ['pending', 'confirmed'], true),
            ]),
            'rooms' => $rooms->map(fn (Room $r) => [
                'id' => $r->id,
                'label' => $r->workspace?->name.' - '.$r->name,
            ])->values()->all(),
            'filters' => ['status' => $status ?? '', 'date' => $date ?? '', 'room_id' => (string) ($roomId ?? '')],
        ]);
    }

    /** One booking row for the calendar lists (day/week/month). */
    private function calendarBookingRow(Booking $b): array
    {
        return [
            'id' => $b->id,
            'time_range' => $b->timeRange(),
            'start_time' => $b->start_time,
            'customer' => $b->hotspotUser?->name,
            'party_size' => (int) $b->party_size,
            'room' => trim(($b->room?->workspace?->name ? $b->room->workspace->name.' / ' : '').$b->room?->name),
            'total_price' => (float) $b->total_price,
            'status_label' => $b->statusLabel(),
            'status_class' => $b->statusBadgeClass(),
        ];
    }

    public function create(Request $request): View
    {
        $ownerId = TenantContext::id();

        $rooms = Room::where('owner_id', $ownerId)
            ->where('is_available', true)
            ->with(['workspace', 'activePricingProfiles'])
            ->get();

        $users = HotspotUser::where('owner_id', $ownerId)
            ->orderBy('name')
            ->get();

        $timeSlots = $this->generateTimeSlots();

        $selectedUserId = $request->get('hotspot_user_id');

        // Prefill from a click-to-book calendar link (room_id/booking_date/
        // start_time/end_time query params). These are only a starting
        // point for the form, never a booking guarantee — store() always
        // re-validates capacity server-side regardless of what's prefilled.
        $selectedRoomId = $request->get('room_id');
        $selectedDate = $request->get('booking_date');
        $selectedStartTime = $request->get('start_time');
        $selectedEndTime = $request->get('end_time');

        return view('bookings.create', compact(
            'rooms', 'users', 'timeSlots', 'selectedUserId',
            'selectedRoomId', 'selectedDate', 'selectedStartTime', 'selectedEndTime',
        ));
    }

    public function store(Request $request, AvailabilityService $availability, BusinessHoursService $businessHours, RoomPricingService $pricing, HourPackageService $packages): RedirectResponse|JsonResponse
    {
        $owner = TenantContext::user();

        // The quick-booking modal posts with Accept: application/json and needs
        // a JSON answer; the full booking page keeps its redirect-with-flash.
        $fail = fn (string $message) => $request->wantsJson()
            ? response()->json(['success' => false, 'message' => $message], 422)
            : back()->withInput()->with('error', $message);
        $ownerId = $owner->id;

        if ($request->input('duration_type') === 'open') {
            return $this->storeOpenSession($request, $owner, $availability, $packages, $fail);
        }

        $rules = [
            'room_id' => 'required|exists:rooms,id',
            'hotspot_user_id' => 'required|exists:hotspot_users,id',
            'booking_date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required_without:room_plan_id|nullable|date_format:H:i|after:start_time',
            'room_plan_id' => 'nullable|integer',
            'room_pricing_profile_id' => 'nullable|integer',
            'party_size' => 'nullable|integer|min:1',
            'guest_count' => 'nullable|integer|min:1|max:999',
            'amount_paid' => 'nullable|numeric|min:0',
            'member_package_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
        ];

        // Web routes only render validation errors as JSON for api/* (bootstrap/app.php),
        // so the modal gets them explicitly; the form keeps the redirect-with-errors.
        if ($request->wantsJson()) {
            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
            }
            $validated = $validator->validated();
        } else {
            $validated = $request->validate($rules);
        }

        $room = Room::where('id', $validated['room_id'])
            ->where('owner_id', $ownerId)
            ->firstOrFail();

        // Shared rooms are advance-bookable (Phase 5) exactly like exclusive
        // ones — the capacity math below already handles both via
        // Room::effectiveCapacity(). What stays deferred is check-in/close
        // linkage and no-show automation (Phase 5c/5d), not the write itself.
        $hotspotUser = HotspotUser::where('id', $validated['hotspot_user_id'])
            ->where('owner_id', $ownerId)
            ->firstOrFail();

        $partySize = (int) ($validated['party_size'] ?? 1);

        // People the price is quoted for. A shared room's party_size already
        // is its headcount (seats); an exclusive room's party_size stays 1
        // (it books the whole room), so its headcount travels separately.
        $people = $room->isShared() ? $partySize : (int) ($validated['guest_count'] ?? 1);
        [$quote, $planId, $planError, $profile] = $this->priceBooking($pricing, $room, $validated, $people);
        if ($planError) {
            return $fail($planError);
        }

        // Checked after pricing: a Custom Plan decides the booking's own window.
        if (! $businessHours->isWithinWorkingHours($owner, $validated['booking_date'], $validated['start_time'], $validated['end_time'])) {
            return $fail(__('app.booking.outside_working_hours'));
        }

        // Shared rooms are billed at checkout (SharedSessionController::
        // close()) once the actual elapsed time is known — their total here
        // is only a preview, so no deposit is accepted against it, no
        // matter what the client submitted. The payment section is already
        // hidden for shared rooms in the UI; this is the server-side
        // enforcement of that same rule.
        // Optional "Use hour package": checked up front for a friendly reason;
        // the balance itself is re-enforced atomically inside the transaction.
        $package = null;
        if (! empty($validated['member_package_id'])) {
            [$package, $packageError] = $this->resolvePackage($packages, (int) $validated['member_package_id'], $hotspotUser->id, $room, $validated, $quote, $partySize);
            if ($packageError) {
                return $fail($packageError);
            }
        }

        // A package-covered booking takes no cash deposit.
        $amountPaid = ($room->isShared() || $package) ? 0.0 : round((float) ($validated['amount_paid'] ?? 0), 2);

        if ($amountPaid > $quote->totalPrice) {
            return $fail(__('app.booking.payment.exceeds_total', ['total' => number_format($quote->totalPrice, 2)]));
        }

        try {
            $booking = DB::transaction(function () use ($validated, $ownerId, $room, $hotspotUser, $quote, $planId, $people, $availability, $partySize, $amountPaid, $package, $packages, $profile) {
                // Lock the room row so concurrent store()/update() calls for
                // this room serialize through here. Genuine row-level locking
                // on MySQL (production) — compiles to a real `FOR UPDATE`; a
                // documented no-op on SQLite (dev/test). The exceedsCapacity()
                // check below is the second, engine-independent layer this
                // doesn't rely on alone: it re-verifies the actual committed
                // state after writing, so correctness doesn't hinge solely on
                // the lock having been honored.
                $lockedRoom = Room::where('id', $room->id)->lockForUpdate()->firstOrFail();

                $remaining = $availability->availabilityForRange(
                    $lockedRoom, $validated['booking_date'], $validated['start_time'], $validated['end_time'],
                );

                if ($remaining < $partySize) {
                    return null;
                }

                $newBooking = Booking::create([
                    'owner_id' => $ownerId,
                    'room_id' => $lockedRoom->id,
                    'room_plan_id' => $planId,
                    'room_pricing_profile_id' => $profile?->id,
                    'pricing_profile_name' => $profile?->name,
                    'hotspot_user_id' => $hotspotUser->id,
                    'party_size' => $partySize,
                    'booking_date' => $validated['booking_date'],
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'guest_count' => $lockedRoom->isShared() ? null : $people,
                    'price_per_hour' => $quote->ratePerHour,
                    'total_hours' => $quote->totalHours(),
                    'total_price' => $quote->totalPrice,
                    'pricing_note' => $quote->note,
                    'amount_paid' => $amountPaid,
                    'payment_status' => Booking::derivePaymentStatus($amountPaid, $quote->totalPrice),
                    'status' => 'confirmed',
                    'notes' => $validated['notes'] ?? null,
                ]);

                if ($availability->exceedsCapacity($lockedRoom, $validated['booking_date'], $validated['start_time'], $validated['end_time'])) {
                    throw new BookingCapacityExceededException;
                }

                if ($package) {
                    $this->coverWithPackage($packages, $newBooking, $package, $quote);
                }

                return $newBooking;
            });
        } catch (BookingCapacityExceededException) {
            $booking = null;
        } catch (InsufficientPackageBalanceException $e) {
            return $fail($e->getMessage());
        }

        if (! $booking) {
            return $fail('This room is already booked for the selected time slot. Please choose a different time.');
        }

        $paidNote = $amountPaid > 0 ? " (paid {$amountPaid})" : '';
        $this->activityLogger->log('booking.created', $booking, "Booked {$booking->room->name} for {$booking->booking_date->format('M d, Y')}{$paidNote}");

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'booking_id' => $booking->id,
                'url' => "/bookings/{$booking->id}",
                'message' => __('app.quick_booking.booked', ['name' => $hotspotUser->name, 'total' => Money::format((float) $booking->total_price)]),
            ]);
        }

        return redirect("/bookings/{$booking->id}")->with('success', 'Booking confirmed successfully.');
    }

    /**
     * Open Session: an exclusive-room booking that starts right now with no
     * end time, occupying the room until BookingController::close() prices
     * and finalizes it. Exclusive rooms only — shared rooms keep using
     * SharedSessionController::store()'s own walk-in flow unchanged.
     * Deliberately skips priceBooking()/resolvePackage()/coverWithPackage():
     * there is nothing to quote yet, and a chosen package is drawn later at
     * checkout by the actual elapsed time (mirroring
     * SharedSessionController::store()'s identical "chosen now, drawn at
     * close" deferral).
     */
    private function storeOpenSession(Request $request, $owner, AvailabilityService $availability, HourPackageService $packages, \Closure $fail): RedirectResponse|JsonResponse
    {
        $ownerId = $owner->id;

        $rules = [
            'room_id' => 'required|exists:rooms,id',
            'hotspot_user_id' => 'required|exists:hotspot_users,id',
            'room_pricing_profile_id' => 'nullable|integer',
            'guest_count' => 'nullable|integer|min:1|max:999',
            'member_package_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
        ];

        if ($request->wantsJson()) {
            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
            }
            $validated = $validator->validated();
        } else {
            $validated = $request->validate($rules);
        }

        $room = Room::where('id', $validated['room_id'])->where('owner_id', $ownerId)->firstOrFail();

        if ($room->isShared()) {
            return $fail(__('app.booking.duration_type.shared_room_not_allowed'));
        }

        $hotspotUser = HotspotUser::where('id', $validated['hotspot_user_id'])->where('owner_id', $ownerId)->firstOrFail();

        $profile = null;
        if (! empty($validated['room_pricing_profile_id'])) {
            try {
                $profile = app(RoomPricingService::class)->resolveProfile($room, (int) $validated['room_pricing_profile_id']);
            } catch (ModelNotFoundException) {
                return $fail(__('app.pricing_profiles.errors.not_available'));
            }
        }

        $packageId = null;
        if (! empty($validated['member_package_id'])) {
            $package = MemberPackage::where('owner_id', $ownerId)
                ->where('hotspot_user_id', $hotspotUser->id)
                ->whereKey($validated['member_package_id'])
                ->firstOrFail();
            if ($reason = $packages->eligibility($package, $room, now()->format('Y-m-d'), 1, 1)) {
                return $fail(__('app.packages.reasons.'.$reason));
            }
            $packageId = $package->id;
        }

        try {
            $booking = DB::transaction(function () use ($room, $ownerId, $hotspotUser, $profile, $packageId, $validated, $availability) {
                $lockedRoom = Room::where('id', $room->id)->lockForUpdate()->firstOrFail();

                if ($availability->availableNow($lockedRoom) < 1) {
                    return null;
                }

                $now = now();

                $newBooking = Booking::create([
                    'owner_id' => $ownerId,
                    'room_id' => $lockedRoom->id,
                    'room_pricing_profile_id' => $profile?->id,
                    'pricing_profile_name' => $profile?->name,
                    'hotspot_user_id' => $hotspotUser->id,
                    'party_size' => 1,
                    'guest_count' => (int) ($validated['guest_count'] ?? 1),
                    'booking_date' => $now->format('Y-m-d'),
                    'start_time' => $now->format('H:i'),
                    'end_time' => null,
                    'price_per_hour' => $profile ? $profile->price_per_hour : $lockedRoom->price_per_hour,
                    'billing_unit' => $lockedRoom->billing_unit,
                    'billing_buffer_minutes' => (int) $lockedRoom->billing_buffer_minutes,
                    'total_hours' => 0,
                    'total_price' => 0,
                    'amount_paid' => 0,
                    'payment_status' => Booking::PAYMENT_UNPAID,
                    'member_package_id' => $packageId,
                    'status' => 'open',
                    'notes' => $validated['notes'] ?? null,
                ]);

                if ($availability->usedCapacityNow($lockedRoom) > $lockedRoom->effectiveCapacity()) {
                    throw new BookingCapacityExceededException;
                }

                return $newBooking;
            });
        } catch (BookingCapacityExceededException) {
            $booking = null;
        }

        if (! $booking) {
            return $fail(__('app.booking.duration_type.room_occupied'));
        }

        $this->activityLogger->log('booking.open_session_started', $booking, "Started an open session for {$booking->room->name}");

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'booking_id' => $booking->id,
                'url' => "/bookings/{$booking->id}",
                'message' => __('app.booking.duration_type.open_started', ['room' => $booking->room->name]),
            ]);
        }

        return redirect("/bookings/{$booking->id}")->with('success', __('app.booking.duration_type.open_started', ['room' => $booking->room->name]));
    }

    /**
     * Owner/member-scope the chosen hour package and check it can cover this
     * booking (room, booking date, balance). Shared rooms use packages at
     * check-in/close instead, since they're billed by actual elapsed time.
     *
     * @return array{0: ?MemberPackage, 1: ?string}
     */
    private function resolvePackage(HourPackageService $packages, int $packageId, int $memberId, Room $room, array $validated, PriceQuote $quote, int $partySize, ?Booking $ignore = null): array
    {
        $package = MemberPackage::where('owner_id', $room->owner_id)
            ->where('hotspot_user_id', $memberId)
            ->whereKey($packageId)
            ->firstOrFail();

        if ($room->isShared()) {
            return [null, __('app.packages.reasons.shared_booking')];
        }

        $reason = $packages->eligibility($package, $room, $validated['booking_date'], (int) ceil($quote->totalMinutes), $partySize, $ignore);
        if ($reason === 'insufficient') {
            return [null, (new InsufficientPackageBalanceException($packages->available($package, $ignore)))->getMessage()];
        }

        return [$reason ? null : $package, $reason ? __('app.packages.reasons.'.$reason) : null];
    }

    /**
     * Draw the booking's minutes from $package (or return them when null) and
     * record it as covered: total_price = amount_paid = the package value those
     * hours represent, which is how the revenue is recognised on completion.
     * Runs inside the caller's transaction.
     */
    private function coverWithPackage(HourPackageService $packages, Booking $booking, ?MemberPackage $package, PriceQuote $quote): void
    {
        $minutes = (int) ceil($quote->totalMinutes);
        $value = $packages->reconcileBooking($booking, $package, $package ? $minutes : 0);

        if (! $package) {
            return;
        }

        $booking->update([
            'payment_method' => Booking::METHOD_PACKAGE,
            'member_package_id' => $package->id,
            'total_price' => $value,
            'amount_paid' => $value,
            'payment_status' => Booking::PAYMENT_PAID,
            'pricing_note' => __('app.packages.covered_note', ['name' => $package->name, 'hours' => Duration::label($minutes)]),
        ]);
    }

    /**
     * Price a booking through RoomPricingService: a Custom Plan when one is
     * chosen (fixed price, and the plan sets the booking's window — so
     * $validated's start/end are replaced by the plan's), otherwise the
     * room's standard pricing — or a chosen Pricing Profile's hourly rate.
     * Plan and profile are alternatives, never combined.
     * Returns [quote, planId, errorMessage, profile].
     *
     * @return array{0: ?PriceQuote, 1: ?int, 2: ?string, 3: ?RoomPricingProfile}
     */
    private function priceBooking(RoomPricingService $pricing, Room $room, array &$validated, int $people, ?int $keepProfileId = null): array
    {
        if (! empty($validated['room_pricing_profile_id']) && ! empty($validated['room_plan_id'])) {
            return [null, null, __('app.pricing_profiles.errors.with_plan'), null];
        }

        if (empty($validated['room_plan_id'])) {
            if (empty($validated['end_time'])) {
                return [null, null, __('validation.required', ['attribute' => 'end time']), null];
            }
            try {
                $profile = $pricing->resolveProfile($room, (int) ($validated['room_pricing_profile_id'] ?? 0) ?: null, $keepProfileId);
            } catch (ModelNotFoundException) {
                // Another room's / tenant's profile, or deactivated — never priced with.
                return [null, null, __('app.pricing_profiles.errors.not_available'), null];
            }

            return [$pricing->quoteBooking($room, $people, $validated['booking_date'], $validated['start_time'], $validated['end_time'], $profile), null, null, $profile];
        }

        $plan = RoomPlan::where('id', $validated['room_plan_id'])
            ->where('owner_id', $room->owner_id)
            ->where('room_id', $room->id)
            ->first();
        if (! $plan) {
            return [null, null, __('app.plans.errors.plan_room'), null];
        }

        try {
            $quote = $pricing->quotePlan($room, $plan, $people, $validated['booking_date'], $validated['start_time']);
        } catch (\InvalidArgumentException $e) {
            return [null, null, __('app.plans.errors.'.$e->getMessage(), ['count' => $plan->people]), null];
        }

        $window = $pricing->planWindow($room, $plan, $validated['booking_date'], $validated['start_time']);
        $validated['start_time'] = $window['start'];
        $validated['end_time'] = $window['end'];

        return [$quote, $plan->id, null, null];
    }

    /**
     * The room's Custom Plans for the quote UI: each with its fixed price, the
     * window it would book from the chosen start, whether it fits the people
     * count, and whether that window is free and within working hours.
     */
    private function planOptions(Room $room, array $validated, ?int $bookingId, int $people, AvailabilityService $availability, BusinessHoursService $businessHours, $owner, RoomPricingService $pricing): array
    {
        return $room->plans->map(function (RoomPlan $plan) use ($room, $validated, $bookingId, $people, $availability, $businessHours, $owner, $pricing) {
            $window = $pricing->planWindow($room, $plan, $validated['booking_date'], $validated['start_time']);
            $state = 'free';
            if (! $window || $window['minutes'] <= 0) {
                $state = 'no_fit';
            } elseif (! $businessHours->isWithinWorkingHours($owner, $validated['booking_date'], $window['start'], $window['end'])) {
                $state = 'outside_hours';
            } elseif ($availability->availabilityForRange($room, $validated['booking_date'], $window['start'], $window['end'], $bookingId) < ($room->isShared() ? $plan->people : 1)) {
                $state = 'booked';
            }

            return [
                'id' => $plan->id,
                'name' => $plan->displayName(),
                'note' => $plan->note(),
                'people' => $plan->people,
                'people_label' => $plan->peopleLabel(),
                'duration_label' => $plan->durationLabel(),
                'price' => (float) $plan->price,
                'price_display' => Money::format((float) $plan->price),
                'start_time' => $window['start'] ?? null,
                'end_time' => $window['end'] ?? null,
                'fits_people' => $people === $plan->people,
                'state' => $state,
            ];
        })->values()->all();
    }

    public function show($id, RoomPricingService $pricing): Response
    {
        $owner = TenantContext::user();

        $booking = Booking::where('owner_id', $owner->id)
            ->with(['room.workspace', 'hotspotUser', 'sale.items'])
            ->findOrFail($id);

        // Catalog for the "add items" picker — only relevant when the sales feature is on.
        $products = $owner->hasFeature('sales')
            ? Product::where('owner_id', $owner->id)->where('is_active', true)->orderBy('name')->get()
            : collect();

        $staff = auth('staff')->user();
        $canDelete = ! $staff || $staff->hasPermission('bookings.delete');

        // On-load snapshot, not a live ticker — the authoritative amount is
        // always recomputed server-side at checkout (closePreview/close).
        $openQuote = $booking->isOpenSession() ? $pricing->quoteOpenBooking($booking, now()) : null;

        $isPackage = $booking->payment_method === Booking::METHOD_PACKAGE;
        $user = $booking->hotspotUser;
        $sale = $booking->sale;

        return Inertia::render('Bookings/Show', [
            'booking' => [
                'id' => $booking->id,
                'number' => str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT),
                'created_label' => $booking->created_at?->format('M d, Y h:i A'),
                'status' => $booking->status,
                'status_label' => $booking->statusLabel(),
                'status_class' => $booking->statusBadgeClass(),
                'is_open' => $booking->isOpenSession(),
                'can_edit_link' => in_array($booking->status, ['pending', 'confirmed'], true),
                'workspace_id' => $booking->room?->workspace?->id,
                'workspace_name' => $booking->room?->workspace?->name,
                'room_name' => $booking->room?->name,
                'customer' => $user ? ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'email' => $user->email] : null,
                'party_size' => (int) $booking->party_size,
                'date_label' => $booking->booking_date->format('l, M d, Y'),
                'time_range' => $booking->timeRange(),
                'duration_label' => $openQuote
                    ? __('app.booking.duration_type.duration_so_far').': '.Duration::label((int) round($openQuote->totalMinutes))
                    : $booking->total_hours.' '.__('app.common.hours'),
                'pricing_note' => $booking->pricing_note,
                'has_plan' => (bool) $booking->room_plan_id,
                'price_per_hour' => (float) $booking->price_per_hour,
                'current_amount' => $openQuote ? (float) $openQuote->totalPrice : null,
                'net_room_charge' => $booking->netRoomCharge(),
                'original_amount_label' => ($booking->coupon_id && $booking->discount_total > 0)
                    ? __('app.coupons.checkout.original_amount', ['amount' => number_format($booking->total_price, 2)])
                    : null,
                'package' => $isPackage ? [
                    'name' => $booking->memberPackage?->name ?? '—',
                    'hours_label' => Duration::label((int) round($booking->total_hours * 60)),
                    'worth_label' => __('app.packages.worth', ['amount' => Money::format($booking->total_price)]),
                ] : null,
                'amount_paid' => (float) $booking->amount_paid,
                'balance_due' => $booking->balanceDue(),
                'payment_status' => $booking->payment_status,
                'payment_status_label' => $booking->paymentStatusLabel(),
                'can_record_payment' => $booking->balanceDue() > 0 && ! in_array($booking->status, ['cancelled', 'no_show'], true),
                'notes' => $booking->notes,
            ],
            'sales' => $owner->hasFeature('sales') ? [
                'editable' => $booking->invoiceIsEditable(),
                'items' => $sale ? $sale->items->map(fn (SaleItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => (int) $item->quantity,
                    'line_total' => (float) $item->line_total,
                ])->values()->all() : [],
                'items_total' => (float) ($sale->total ?? 0),
                'room_charge' => $booking->netRoomCharge(),
                'grand_total' => $booking->grandTotal(),
                'products' => $products->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price' => (float) $p->price,
                ])->values()->all(),
            ] : null,
            'canDelete' => $canDelete,
        ]);
    }

    public function edit($id): View
    {
        $ownerId = TenantContext::id();

        $booking = Booking::where('owner_id', $ownerId)
            ->with(['room.workspace', 'hotspotUser'])
            ->findOrFail($id);

        if (! in_array($booking->status, ['pending', 'confirmed'])) {
            return redirect("/bookings/{$id}")->with('error', 'Only pending or confirmed bookings can be edited.');
        }

        $rooms = Room::where('owner_id', $ownerId)
            ->where('is_available', true)
            ->with('workspace')
            ->get();

        $users = HotspotUser::where('owner_id', $ownerId)
            ->orderBy('name')
            ->get();

        $timeSlots = $this->generateTimeSlots();

        return view('bookings.edit', compact('booking', 'rooms', 'users', 'timeSlots'));
    }

    public function update(Request $request, $id, AvailabilityService $availability, BusinessHoursService $businessHours, RoomPricingService $pricing, CouponService $coupons, HourPackageService $packages): RedirectResponse
    {
        $owner = TenantContext::user();
        $ownerId = $owner->id;

        $booking = Booking::where('owner_id', $ownerId)->findOrFail($id);

        if (! in_array($booking->status, ['pending', 'confirmed'])) {
            return back()->with('error', 'Only pending or confirmed bookings can be edited.');
        }

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'hotspot_user_id' => 'required|exists:hotspot_users,id',
            'booking_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required_without:room_plan_id|nullable|date_format:H:i|after:start_time',
            'room_plan_id' => 'nullable|integer',
            'room_pricing_profile_id' => 'nullable|integer',
            'party_size' => 'nullable|integer|min:1',
            'guest_count' => 'nullable|integer|min:1|max:999',
            'amount_paid' => 'nullable|numeric|min:0',
            'member_package_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
        ]);

        $wasCovered = $booking->isPackageCovered();

        $room = Room::where('id', $validated['room_id'])
            ->where('owner_id', $ownerId)
            ->firstOrFail();

        $partySize = (int) ($validated['party_size'] ?? 1);
        $people = $room->isShared() ? $partySize : (int) ($validated['guest_count'] ?? $booking->guest_count ?? 1);
        // A form that doesn't send the field keeps the booking's profile (same room, no plan).
        if (! $request->exists('room_pricing_profile_id') && $booking->room_pricing_profile_id
            && (int) $validated['room_id'] === (int) $booking->room_id && empty($validated['room_plan_id'])) {
            $validated['room_pricing_profile_id'] = $booking->room_pricing_profile_id;
        }

        // A pricing profile kept from before still prices this edit even if it
        // was deactivated since; one from another room is rejected (404).
        [$quote, $planId, $planError, $profile] = $this->priceBooking($pricing, $room, $validated, $people, $booking->room_pricing_profile_id);
        if ($planError) {
            return back()->withInput()->with('error', $planError);
        }

        if (! $businessHours->isWithinWorkingHours($owner, $validated['booking_date'], $validated['start_time'], $validated['end_time'])) {
            return back()->withInput()->with('error', __('app.booking.outside_working_hours'));
        }

        // Falls back to whatever was already recorded so editing the time/
        // room doesn't silently wipe a deposit the request didn't mention —
        // but it's still re-validated against the *new* total below, since
        // shortening the booking could make an old deposit exceed it.
        // A form that doesn't send the field keeps the booking's current package.
        $packageId = $request->exists('member_package_id') ? ($validated['member_package_id'] ?? null) : $booking->member_package_id;
        $package = null;
        if ($packageId) {
            [$package, $packageError] = $this->resolvePackage($packages, (int) $packageId, (int) $validated['hotspot_user_id'], $room, $validated, $quote, $partySize, $booking);
            if ($packageError) {
                return back()->withInput()->with('error', $packageError);
            }
        }

        // A package booking's amount_paid is the package value, never a cash
        // deposit — switching it back to normal payment starts from 0.
        $amountPaid = ($room->isShared() || $package) ? 0.0
            : round((float) ($validated['amount_paid'] ?? ($wasCovered ? 0 : $booking->amount_paid)), 2);

        if ($amountPaid > $quote->totalPrice) {
            return back()->withInput()->with('error', __('app.booking.payment.exceeds_total', ['total' => number_format($quote->totalPrice, 2)]));
        }

        try {
            $updated = DB::transaction(function () use ($validated, $room, $booking, $quote, $planId, $people, $availability, $id, $partySize, $amountPaid, $package, $packages, $profile) {
                // Same lock + post-write re-verify pattern as store() — see
                // the comment there for why both layers exist.
                $lockedRoom = Room::where('id', $room->id)->lockForUpdate()->firstOrFail();

                $remaining = $availability->availabilityForRange(
                    $lockedRoom, $validated['booking_date'], $validated['start_time'], $validated['end_time'],
                    excludeBookingId: (int) $id,
                );

                if ($remaining < $partySize) {
                    return false;
                }

                $booking->update([
                    'room_id' => $lockedRoom->id,
                    'room_plan_id' => $planId,
                    'room_pricing_profile_id' => $profile?->id,
                    'pricing_profile_name' => $profile?->name,
                    'hotspot_user_id' => $validated['hotspot_user_id'],
                    'party_size' => $partySize,
                    'booking_date' => $validated['booking_date'],
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'guest_count' => $lockedRoom->isShared() ? null : $people,
                    'price_per_hour' => $quote->ratePerHour,
                    'total_hours' => $quote->totalHours(),
                    'total_price' => $quote->totalPrice,
                    'pricing_note' => $quote->note,
                    'amount_paid' => $amountPaid,
                    'payment_status' => Booking::derivePaymentStatus($amountPaid, $quote->totalPrice),
                    'payment_method' => 'cash',
                    'member_package_id' => null,
                    'notes' => $validated['notes'] ?? null,
                ]);

                if ($availability->exceedsCapacity($lockedRoom, $validated['booking_date'], $validated['start_time'], $validated['end_time'])) {
                    throw new BookingCapacityExceededException;
                }

                // Reconcile the package hours (2h→3h draws 1h more, 3h→1h
                // returns 2h, switched/removed package returns everything).
                $this->coverWithPackage($packages, $booking, $package, $quote);

                return true;
            });
        } catch (BookingCapacityExceededException) {
            $updated = false;
        } catch (InsufficientPackageBalanceException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if (! $updated) {
            return back()->withInput()->with('error',
                'This room is already booked for the selected time slot. Please choose a different time.');
        }

        $this->activityLogger->log('booking.updated', $booking, "Updated booking #{$booking->id}");

        // The room/time (and so total_price) may have just changed under an
        // already-attached coupon — re-evaluate against the fresh totals
        // rather than leave a stale discount_total on the books.
        $warning = $coupons->syncBooking($booking->fresh(['sale.items']));

        $redirect = redirect("/bookings/{$id}")->with('success', 'Booking updated successfully.');

        return $warning ? $redirect->with('warning', $warning) : $redirect;
    }

    public function updateStatus(Request $request, $id, CouponService $coupons, HourPackageService $packages, InventoryService $inventory): RedirectResponse
    {
        $booking = Booking::where('owner_id', TenantContext::id())->with('room')->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,completed,cancelled',
        ]);

        // 'checked_in'/'no_show' are never reachable through this generic
        // endpoint (not in the validation whitelist above) — they only
        // happen through checkIn() and the no-show sweep, so the invariant
        // "checked_in ⟺ an open SharedSession exists for this booking"
        // can't be bypassed here.
        $validTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];

        // A shared-room reservation must go through check-in to become
        // "completed" — its pricing only becomes real at that point. Direct
        // pending/confirmed -> completed here would freeze it at the
        // pre-arrival estimate and skip creating the session that's the
        // entire point of the reservation. Cancelling is still allowed.
        if ($booking->room->isShared() && $validated['status'] === 'completed') {
            return back()->with('error', 'Shared-room reservations must be checked in, not marked completed directly.');
        }

        if (! in_array($validated['status'], $validTransitions[$booking->status] ?? [])) {
            return back()->with('error', 'Invalid status transition.');
        }

        // The permission:bookings.edit,bookings.cancel route middleware lets
        // either grant reach this shared status-machine endpoint; only the
        // target status here tells you which capability actually applies.
        $staff = auth('staff')->user();
        $requiredPermission = $validated['status'] === 'cancelled' ? 'bookings.cancel' : 'bookings.edit';
        if ($staff && ! $staff->hasPermission($requiredPermission)) {
            return back()->with('permission_denied', __('app.msg.permission_denied'));
        }

        if ($validated['status'] === 'completed') {
            // The in-memory $booking->status check above is not itself a
            // race guard — this atomic conditional update is: only the
            // request that actually flips confirmed -> completed here goes
            // on to redeem any attached coupon, exactly once. A coupon
            // rejection (no longer valid, limit reached by a racing
            // completion, etc.) rolls back the whole transaction, so the
            // booking is left confirmed rather than silently completed with
            // a discount that was never actually granted.
            try {
                $claimed = DB::transaction(function () use ($booking, $coupons) {
                    $updated = Booking::where('id', $booking->id)
                        ->where('owner_id', $booking->owner_id)
                        ->where('status', 'confirmed')
                        ->update(['status' => 'completed']);

                    if ($updated) {
                        $coupons->redeemForBooking($booking->fresh(['sale.items']));
                    }

                    return (bool) $updated;
                });
            } catch (CouponRejectedException|CouponUsageLimitExceededException $e) {
                return back()->with('error', __('app.coupons.errors.cannot_complete', ['reason' => $e->getMessage()]));
            }

            if (! $claimed) {
                return back()->with('error', 'Invalid status transition.');
            }
        } elseif ($validated['status'] === 'cancelled') {
            // Atomic claim so two racing cancels can't both return the
            // booking's package hours; history rows are kept either way.
            $claimed = DB::transaction(function () use ($booking, $packages, $inventory) {
                $updated = Booking::where('id', $booking->id)
                    ->where('owner_id', $booking->owner_id)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->update(['status' => 'cancelled']);

                if ($updated) {
                    $packages->releaseBooking($booking);

                    // SalesService::saleForBooking() marks a Sale 'completed'
                    // the instant it's created, independent of the booking's
                    // own status (the invoice is editable while still
                    // pending/confirmed — Booking::invoiceIsEditable()).
                    // Without this, cancelling a booking that already has
                    // products on its invoice would leave that Sale
                    // 'completed' forever: stock never returned, and every
                    // revenue/product report keeps counting it as real.
                    $sale = $booking->sale;
                    if ($sale && $sale->status !== 'cancelled') {
                        foreach ($sale->items as $item) {
                            $inventory->giveBack($item);
                        }
                        $sale->update(['status' => 'cancelled']);
                    }
                }

                return (bool) $updated;
            });

            if (! $claimed) {
                return back()->with('error', 'Invalid status transition.');
            }
        } else {
            $booking->update(['status' => $validated['status']]);
        }

        $booking->refresh();
        $action = $validated['status'] === 'cancelled' ? 'booking.cancelled' : 'booking.status_changed';
        $this->activityLogger->log($action, $booking, "Booking #{$booking->id} status changed to {$booking->statusLabel()}");

        return back()->with('success', 'Booking status updated to '.$booking->statusLabel().'.');
    }

    /**
     * Claim a still-open shared-room reservation into a live, billable
     * SharedSession. The actual headcount is entered here — it may be less
     * than the original party_size (a partial no-show); the gap is
     * recoverable via Booking::noShowSeats() rather than overwriting the
     * reservation's own party_size. Never available for exclusive rooms —
     * they have no check-in concept and keep their existing four-status
     * lifecycle untouched.
     */
    public function checkIn(Request $request, $id, AvailabilityService $availability, BusinessHoursService $businessHours, RoomPricingService $pricing, HourPackageService $packages): RedirectResponse
    {
        $owner = TenantContext::user();
        $ownerId = $owner->id;

        $booking = Booking::where('owner_id', $ownerId)->with('room')->findOrFail($id);

        if (! $booking->room->isShared()) {
            return back()->with('error', 'Only shared-room reservations can be checked in.');
        }

        if ($booking->status !== 'confirmed') {
            return back()->with('error', 'This reservation can no longer be checked in.');
        }

        // Live check, not a trust in the scheduled sweep: a reservation past
        // its grace period is effectively a no-show even if the periodic
        // sweep hasn't flipped its status yet.
        if ($booking->isPastNoShowGrace()) {
            return back()->with('error', 'This reservation has expired and can no longer be checked in.');
        }

        // Same live-instant check as SharedSessionController::store() — a
        // reservation's own working-hours validity was already checked at
        // booking time, but check-in can happen well after, so it's
        // re-evaluated against right now.
        if (! $businessHours->isOpenNow($owner)) {
            return back()->with('error', __('app.booking.outside_working_hours'));
        }

        $validated = $request->validate([
            'party_size' => 'required|integer|min:1',
            'member_package_id' => 'nullable|integer',
        ]);
        $actualPartySize = (int) $validated['party_size'];

        // Optional hour package for the session (drawn at close by actual time).
        $packageId = null;
        if (! empty($validated['member_package_id'])) {
            $package = MemberPackage::where('owner_id', $ownerId)
                ->where('hotspot_user_id', $booking->hotspot_user_id)
                ->whereKey($validated['member_package_id'])
                ->firstOrFail();
            if ($reason = $packages->eligibility($package, $booking->room, today(), 1, $actualPartySize)) {
                return back()->with('error', __('app.packages.reasons.'.$reason));
            }
            $packageId = $package->id;
        }

        try {
            $session = DB::transaction(function () use ($booking, $ownerId, $actualPartySize, $availability, $pricing, $packageId) {
                $lockedRoom = Room::where('id', $booking->room_id)->lockForUpdate()->firstOrFail();

                // Atomic claim: only one concurrent check-in attempt on this
                // booking can win. If another request (or the no-show sweep)
                // already moved it off 'confirmed', this affects 0 rows.
                $claimed = Booking::where('id', $booking->id)
                    ->where('status', 'confirmed')
                    ->update(['status' => 'checked_in', 'checked_in_party_size' => $actualPartySize]);

                if ($claimed === 0) {
                    return null;
                }

                // Early check-in is allowed (the design decision), provided
                // the room has room right now — checked against the "right
                // now" formula with this booking's own (about to be
                // replaced) reserved seats excluded, so its old party_size
                // isn't double-counted against the actual headcount walking
                // in for it.
                $available = $availability->availableNow($lockedRoom, excludeBookingId: $booking->id);
                if ($actualPartySize > $available) {
                    throw new SharedSessionCapacityExceededException;
                }

                $now = now();
                // Snapshotted from the locked room now, at check-in time — same
                // reasoning as SharedSessionController::store()'s walk-in path.
                // Without this, a rate/billing-unit change made after check-in
                // would silently apply to this session's close-time bill.
                $newSession = SharedSession::create([
                    'owner_id' => $ownerId,
                    'room_id' => $lockedRoom->id,
                    'hotspot_user_id' => $booking->hotspot_user_id,
                    'party_size' => $actualPartySize,
                    'session_date' => $now->format('Y-m-d'),
                    'start_time' => $now->format('H:i'),
                    'opened_at' => $now,
                    'status' => 'open',
                    'booking_id' => $booking->id,
                    'billing_unit' => $lockedRoom->billing_unit,
                    'billing_buffer_minutes' => (int) $lockedRoom->billing_buffer_minutes,
                    // A reservation priced with a profile keeps the rate it was
                    // booked at (its own snapshot), not today's profile price.
                    'billed_price_per_hour' => $booking->room_pricing_profile_id ? $booking->price_per_hour : $lockedRoom->price_per_hour,
                    'pricing_snapshot' => $booking->room_pricing_profile_id ? null : $pricing->snapshotFor($lockedRoom),
                    'room_pricing_profile_id' => $booking->room_pricing_profile_id,
                    'pricing_profile_name' => $booking->pricing_profile_name,
                    'plan_snapshot' => $pricing->planSnapshotFor($booking),
                    'member_package_id' => $packageId,
                ]);

                // Post-write defense-in-depth, same reasoning as store()'s.
                if ($availability->usedCapacityNow($lockedRoom) > $lockedRoom->effectiveCapacity()) {
                    throw new SharedSessionCapacityExceededException;
                }

                return $newSession;
            });
        } catch (SharedSessionCapacityExceededException) {
            return back()->with('error', 'Not enough seats available right now for that many people.');
        }

        if (! $session) {
            return back()->with('error', 'This reservation was already checked in or is no longer available.');
        }

        $this->activityLogger->log('booking.checked_in', $booking, "Checked in booking #{$booking->id}");

        return redirect()->route('active-sessions.index')
            ->with('success', 'Checked in. The session is now open.');
    }

    /**
     * Live preview of what checking out an Open Session would charge right
     * now. Pure read — never writes, so close() re-running the identical
     * quote inside its own transaction can never disagree with this.
     */
    public function closePreview(int $id, Request $request, RoomPricingService $pricing, CouponService $coupons, HourPackageService $packages): JsonResponse
    {
        $booking = Booking::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->where('status', 'open')
            ->with(['room', 'hotspotUser', 'sale.items', 'memberPackage'])
            ->firstOrFail();

        $closedAt = now();
        $quote = $pricing->quoteOpenBooking($booking, $closedAt);

        $duration = $this->formatMinutes($quote->totalMinutes);
        $billedDuration = abs($quote->totalMinutes - $quote->billedMinutes) > 0.01
            ? $this->formatMinutes($quote->billedMinutes)
            : null;

        $itemsTotal = (float) ($booking->sale?->total ?? 0);
        $subtotal = round($quote->totalPrice + $itemsTotal, 2);
        $grandTotal = $subtotal;

        $packagePayload = null;
        if ($pkg = $booking->memberPackage) {
            $minutes = Duration::packageMinutes($quote->totalMinutes);
            $covers = $packages->eligibility($pkg, $booking->room, $booking->booking_date->format('Y-m-d'), $minutes, 1) === null;
            $packagePayload = [
                'name' => $pkg->name,
                'hours' => Duration::label($minutes),
                'remaining' => $pkg->remainingLabel(),
                'covers' => $covers,
                'message' => $covers
                    ? __('app.packages.session_covered', ['name' => $pkg->name, 'hours' => Duration::label($minutes)])
                    : __('app.packages.session_fallback', ['remaining' => $pkg->remainingLabel()]),
            ];
            if ($covers) {
                $grandTotal = round($itemsTotal, 2);
            }
        }

        $couponPayload = null;
        $couponError = null;
        if ($request->filled('coupon_code') && ! ($packagePayload['covers'] ?? false)) {
            try {
                $coupon = $coupons->find(TenantContext::id(), $request->input('coupon_code'));
                $breakdown = $coupons->evaluate($coupon, $coupons->cartForOpenBooking($booking, $quote), $booking->hotspot_user_id);
                $couponPayload = $breakdown->toArray();
                $grandTotal = $breakdown->total();
            } catch (CouponRejectedException $e) {
                $couponError = $e->getMessage();
            }
        }

        return response()->json([
            'session_id' => $booking->id,
            'user_name' => $booking->hotspotUser->name,
            'user_phone' => $booking->hotspotUser->phone,
            'room_name' => $booking->room->name,
            'party_size' => 1,
            'start_time' => $booking->startsAt()->format('h:i A'),
            'end_time' => $closedAt->format('h:i A'),
            'closed_at_datetime' => $closedAt->toDateTimeString(),
            'duration' => $duration,
            'billed_duration' => $billedDuration,
            'total_minutes' => $quote->totalMinutes,
            'price_per_hour' => number_format($quote->ratePerHour, 2),
            'pricing_note' => $quote->note,
            'total_price' => number_format($quote->totalPrice, 2),
            'total_price_raw' => $quote->totalPrice,
            'items' => $this->itemsPayloadFor($booking),
            'items_total' => number_format($itemsTotal, 2),
            'subtotal' => number_format($subtotal, 2),
            'grand_total' => number_format($grandTotal, 2),
            'coupon' => $couponPayload,
            'coupon_error' => $couponError,
            'package' => $packagePayload,
        ]);
    }

    /**
     * Check out an Open Session: atomically claim it (same row, no
     * Booking::create()/transferToBooking() needed — this booking IS the
     * live record and any Sale was attached to it the whole time), price it
     * server-side, draw a chosen package's actual minutes, apply an optional
     * coupon — mirroring SharedSessionController::close()'s exact shape.
     */
    public function close(Request $request, int $id, RoomPricingService $pricing, CouponService $coupons, HourPackageService $packages): JsonResponse
    {
        $ownerId = TenantContext::id();
        $closedAt = now();
        $couponCode = $request->input('coupon_code');

        if ($couponCode) {
            $staff = auth('staff')->user();
            if ($staff && ! $staff->hasPermission('coupons.apply')) {
                return response()->json(['success' => false, 'message' => __('app.msg.permission_denied')], 403);
            }
        }

        try {
            return DB::transaction(function () use ($id, $ownerId, $closedAt, $pricing, $coupons, $couponCode, $packages) {
                $claimed = Booking::where('id', $id)
                    ->where('owner_id', $ownerId)
                    ->where('status', 'open')
                    ->update(['status' => 'completed', 'end_time' => $closedAt->format('H:i')]);

                if ($claimed === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => __('app.booking.duration_type.already_closed'),
                    ], 409);
                }

                $booking = Booking::where('id', $id)
                    ->where('owner_id', $ownerId)
                    ->with(['room', 'hotspotUser', 'sale'])
                    ->firstOrFail();

                $quote = $pricing->quoteOpenBooking($booking, $closedAt);

                $booking->update([
                    'total_hours' => $quote->billedHours(),
                    'total_price' => $quote->totalPrice,
                    'pricing_note' => $quote->note,
                    'amount_paid' => $quote->totalPrice,
                    'payment_status' => Booking::PAYMENT_PAID,
                ]);

                // Hour package chosen at open: draw the actual elapsed minutes.
                // Not enough left (or no longer valid) → normal billing as above.
                $coveredByPackage = false;
                $pkg = $booking->member_package_id
                    ? MemberPackage::where('owner_id', $ownerId)->find($booking->member_package_id)
                    : null;
                if ($pkg) {
                    $minutes = Duration::packageMinutes($quote->totalMinutes);
                    $covered = null;
                    if ($packages->eligibility($pkg, $booking->room, $booking->booking_date->format('Y-m-d'), $minutes, 1) === null) {
                        try {
                            $covered = $packages->consume($pkg, $minutes, PackageUsage::BOOKING_USAGE, $booking);
                        } catch (InsufficientPackageBalanceException) {
                            $covered = null; // the guarded UPDATE wrote nothing — safe to fall back
                        }
                    }

                    if ($covered) {
                        $coveredByPackage = true;
                        $value = (float) $covered->value;
                        $booking->update([
                            'payment_method' => Booking::METHOD_PACKAGE,
                            'member_package_id' => $pkg->id,
                            'total_price' => $value,
                            'amount_paid' => $value,
                            'payment_status' => Booking::PAYMENT_PAID,
                            'pricing_note' => __('app.packages.covered_note', ['name' => $pkg->name, 'hours' => Duration::label($minutes)]),
                        ]);
                    } else {
                        $booking->update(['notes' => trim($booking->notes.' '.__('app.packages.session_fallback', ['remaining' => $pkg->fresh()->remainingLabel()]))]);
                    }
                }

                // A coupon rejection here throws and rolls back the whole
                // transaction — the booking re-opens (the atomic claim above
                // is undone too), and no usage row is left behind.
                if ($couponCode && ! $coveredByPackage) {
                    $coupon = $coupons->find($ownerId, $couponCode);
                    $booking->update(['coupon_id' => $coupon->id]);
                    $coupons->redeemForBooking($booking->fresh(['sale.items']));
                    $booking->refresh();
                    $booking->update([
                        'amount_paid' => $booking->netRoomCharge(),
                        'payment_status' => Booking::PAYMENT_PAID,
                    ]);
                }

                $grandTotal = $booking->fresh('sale')->grandTotal();

                $this->activityLogger->log('booking.open_session_closed', $booking, "Closed open session #{$booking->id}, total ج.م ".number_format($grandTotal, 2));

                return response()->json([
                    'success' => true,
                    'message' => __('app.booking.duration_type.checked_out', ['amount' => number_format($grandTotal, 2)]),
                    'booking_id' => $booking->id,
                ]);
            });
        } catch (CouponRejectedException|CouponUsageLimitExceededException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** 'Xh Ym' for a fractional-minutes value — mirrors SharedSessionController's identical helper. */
    private function formatMinutes(float $minutes): string
    {
        $h = intdiv((int) $minutes, 60);
        $m = (int) $minutes % 60;

        return ($h > 0 ? $h.'h ' : '').$m.'m';
    }

    /** Serialise an open booking's line items for the close preview modal. */
    private function itemsPayloadFor(Booking $booking): array
    {
        if (! $booking->sale) {
            return [];
        }

        return $booking->sale->items->map(fn (SaleItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit_price' => number_format($item->unit_price, 2),
            'line_total' => number_format($item->line_total, 2),
        ])->all();
    }

    /**
     * Delete a mistaken booking and fully reverse everything it caused:
     * restore any Sale's inventory then delete the Sale (its booking_id FK is
     * nullOnDelete, not cascade — leaving it orphaned would keep counting in
     * RevenueAnalyticsService::saleRevenue() forever), release any Member
     * Hour Package minutes it held, and release any redeemed coupon usage
     * (same nullOnDelete leak against Coupon usage-limit counting). Revenue
     * itself needs no separate reversal — it's a live sum over bookings/sales,
     * so it's correct again the instant both rows are actually gone.
     *
     * Blocked outright for a 'checked_in' or 'open' booking — both are this
     * codebase's markers for "this booking has a currently active session"
     * (checked_in via checkIn()'s SharedSession, open via an Open Session —
     * nothing transitions a booking out of either except closing the
     * session), so deleting one here would corrupt a live session.
     *
     * The inner atomic re-check (lockForUpdate + a status whitelist) is the
     * same two-phase pattern updateStatus() already uses for its cancel/
     * complete branches: it's what actually makes a duplicate/double-submit
     * delete request a no-op instead of a double reversal, not the outer
     * findOrFail (which only guards the normal 404/UX case).
     */
    public function destroy($id, CouponService $coupons, HourPackageService $packages, SalesService $sales): RedirectResponse
    {
        $booking = Booking::where('owner_id', TenantContext::id())->with(['sale.items', 'couponUsage'])->findOrFail($id);

        if ($booking->status === 'checked_in') {
            return back()->with('error', __('app.booking.delete_disabled_checked_in'));
        }

        if ($booking->isOpenSession()) {
            return back()->with('error', __('app.booking.duration_type.delete_disabled_open'));
        }

        try {
            $deleted = DB::transaction(function () use ($booking, $coupons, $packages, $sales) {
                $locked = Booking::where('id', $booking->id)
                    ->where('owner_id', $booking->owner_id)
                    ->whereIn('status', ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'])
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return false;
                }

                $restoredItems = [];
                $sale = $locked->sale;
                if ($sale) {
                    foreach ($sale->items->all() as $item) {
                        $restoredItems[] = ['product' => $item->name, 'quantity' => $item->quantity];
                        $sales->removeItem($item);
                    }
                    $sale->delete();
                }

                $packageMinutesHeld = (int) round((float) $locked->total_hours * 60);
                $packages->releaseBooking($locked);

                $couponUsage = $locked->couponUsage;
                $coupons->releaseBooking($locked);

                $this->activityLogger->log('booking.deleted', $locked, "Deleted booking #{$locked->id}", [
                    'status_at_deletion' => $locked->status,
                    'amount_paid' => (float) $locked->amount_paid,
                    'discount_total' => (float) $locked->discount_total,
                    'payment_method' => $locked->payment_method,
                    'package_minutes_released' => $locked->isPackageCovered() ? $packageMinutesHeld : 0,
                    'member_package_id' => $locked->member_package_id,
                    'coupon_code' => $couponUsage?->coupon?->code,
                    'coupon_usage_id_removed' => $couponUsage?->id,
                    'sale_items_restored' => $restoredItems,
                ]);

                $locked->delete();

                return true;
            });
        } catch (\Throwable $e) {
            return back()->with('error', __('app.booking.delete_failed'));
        }

        if (! $deleted) {
            return back()->with('error', __('app.booking.delete_already_gone'));
        }

        return redirect('/bookings')->with('success', __('app.booking.deleted_successfully'));
    }

    /**
     * Day view is the default landing state — "what does my space look like
     * right now." Week reuses day's per-room rendering for each of 7 days;
     * month keeps its existing grid. AvailabilityService is the only source
     * of booked/available data for day and week — this method only shapes
     * its output per room/day for the view, no capacity math of its own.
     */
    public function calendar(Request $request, AvailabilityService $availability): Response
    {
        $ownerId = TenantContext::id();

        $view = $request->get('view', 'day');
        if (! in_array($view, ['day', 'week', 'month'], true)) {
            $view = 'day';
        }

        $date = $request->get('date', now()->format('Y-m-d'));
        $carbon = Carbon::parse($date);
        $roomId = $request->get('room_id');
        $workspaceId = $request->get('workspace_id');

        $workspaces = Workspace::where('owner_id', $ownerId)->orderBy('name')->get();

        // Unfiltered list for the filter dropdown, so picking a room doesn't
        // collapse the dropdown down to just that one option afterward.
        $allRooms = Room::where('owner_id', $ownerId)
            ->where('is_available', true)
            ->with('workspace')
            ->orderBy('name')
            ->get();

        $rooms = $allRooms
            ->when($workspaceId, fn ($c) => $c->where('workspace_id', (int) $workspaceId))
            ->when($roomId, fn ($c) => $c->where('id', (int) $roomId))
            ->values();

        $today = now()->format('Y-m-d');
        $data = [
            'view' => $view,
            'date' => $date,
            'roomId' => (string) ($roomId ?? ''),
            'workspaceId' => (string) ($workspaceId ?? ''),
            'today' => $today,
            'workspaces' => $workspaces->map(fn (Workspace $ws) => ['id' => $ws->id, 'name' => $ws->name])->values()->all(),
            'allRooms' => $allRooms->map(fn (Room $r) => ['id' => $r->id, 'label' => $r->workspace?->name.' - '.$r->name])->values()->all(),
            // Legend status pills — the same model helpers every status badge uses.
            'statusLegend' => collect(['pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'])
                ->map(function (string $status) {
                    $preview = new Booking(['status' => $status]);

                    return ['label' => $preview->statusLabel(), 'class' => $preview->statusBadgeClass()];
                })->all(),
        ];

        if ($view === 'month') {
            $bookings = Booking::where('owner_id', $ownerId)
                ->with(['room.workspace', 'hotspotUser'])
                ->whereMonth('booking_date', $carbon->month)
                ->whereYear('booking_date', $carbon->year)
                ->where('status', '!=', 'cancelled')
                ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
                ->when($workspaceId, fn ($q) => $q->whereHas('room', fn ($rq) => $rq->where('workspace_id', $workspaceId)))
                ->get()
                ->groupBy(fn ($b) => $b->booking_date->format('Y-m-d'));

            $data['bookings'] = $bookings
                ->map(fn ($dayBookings) => $dayBookings->sortBy('start_time')->map(fn (Booking $b) => $this->calendarBookingRow($b))->values()->all())
                ->all();

            $startOfGrid = $carbon->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
            $endOfGrid = $carbon->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
            $cells = [];
            for ($d = $startOfGrid->copy(); $d->lte($endOfGrid); $d->addDay()) {
                $dateStr = $d->format('Y-m-d');
                $cells[] = [
                    'date' => $dateStr,
                    'day' => $d->format('j'),
                    'in_month' => $d->month === $carbon->month,
                    'is_today' => $dateStr === $today,
                    'is_selected' => $dateStr === $date,
                    'count' => isset($bookings[$dateStr]) ? $bookings[$dateStr]->count() : 0,
                ];
            }
            $data['month'] = [
                'title' => $carbon->format('F Y'),
                'prev' => $carbon->copy()->subMonth()->format('Y-m-d'),
                'next' => $carbon->copy()->addMonth()->format('Y-m-d'),
                'cells' => $cells,
                'selectedLabel' => Carbon::parse($date)->format('l, M d, Y'),
            ];
        } elseif ($view === 'week') {
            $startOfWeek = $carbon->copy()->startOfWeek(Carbon::MONDAY);
            $data['days'] = collect(range(0, 6))->map(function (int $i) use ($startOfWeek, $rooms, $availability, $today) {
                $day = $startOfWeek->copy()->addDays($i);

                return [
                    'date' => $day->format('Y-m-d'),
                    'label' => $day->format('l, M d'),
                    'is_today' => $day->format('Y-m-d') === $today,
                    'rooms' => $this->roomDayAvailability($rooms, $day->format('Y-m-d'), $availability),
                ];
            })->all();
            $data['week'] = [
                'title' => $startOfWeek->format('M d').' – '.$startOfWeek->copy()->addDays(6)->format('M d, Y'),
                'prev' => $startOfWeek->copy()->subWeek()->format('Y-m-d'),
                'next' => $startOfWeek->copy()->addWeek()->format('Y-m-d'),
            ];
        } else {
            $data['dayRooms'] = $this->roomDayAvailability($rooms, $carbon->format('Y-m-d'), $availability);
            $data['day'] = [
                'title' => $carbon->format('l, M d, Y'),
                'prev' => $carbon->copy()->subDay()->format('Y-m-d'),
                'next' => $carbon->copy()->addDay()->format('Y-m-d'),
            ];
        }

        return Inertia::render('Bookings/Calendar', $data);
    }

    /**
     * Per-room booked/available blocks plus that day's actual bookings, for
     * the day and week views. Purely a packaging step around
     * AvailabilityService — no availability logic lives here or in the view.
     */
    private function roomDayAvailability($rooms, string $dateStr, AvailabilityService $availability): array
    {
        $isToday = $dateStr === now()->format('Y-m-d');

        return $rooms->map(fn (Room $room) => [
            'room' => [
                'id' => $room->id,
                'name' => $room->name,
                'workspace' => $room->workspace?->name,
                'type_label' => $room->typeLabel(),
                'type_color' => $room->typeColor(),
            ],
            'date' => $dateStr,
            'blocks' => $availability->freeBusyForDay($room, $dateStr),
            // Click-to-book pills: label + prefilled /bookings/create link decided here.
            'slots' => array_map(fn (array $slot) => $slot + [
                'label' => Carbon::createFromFormat('H:i', $slot['start'])->format('h:i A'),
                'create_url' => $slot['available']
                    ? '/bookings/create?'.http_build_query(['room_id' => $room->id, 'booking_date' => $dateStr, 'start_time' => $slot['start'], 'end_time' => $slot['end']])
                    : null,
            ], $availability->bookableSlots($room, $dateStr)),
            'bookings' => $room->bookings()
                ->whereDate('booking_date', $dateStr)
                ->where('status', '!=', 'cancelled')
                ->with('hotspotUser')
                ->orderBy('start_time')
                ->get()
                ->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'time_range' => $b->timeRange(),
                    'customer' => $b->hotspotUser?->name,
                    'party_size' => (int) $b->party_size,
                    'status_label' => $b->statusLabel(),
                    'status_class' => $b->statusBadgeClass(),
                ])->values()->all(),
            'live' => ($isToday && $room->isShared()) ? $availability->liveOccupancy($room) : null,
        ])->all();
    }

    /**
     * Standalone quick-lookup page: pick a room/date/time/party size and see
     * whether it's available, without going through the booking form. Pure
     * page shell — the actual answer comes from the same checkAvailability()
     * JSON endpoint the create/edit forms already call, so there is exactly
     * one place that decides availability, not a second copy for this page.
     */
    public function availabilityLookup(Request $request): Response
    {
        $ownerId = TenantContext::id();

        $rooms = Room::where('owner_id', $ownerId)
            ->where('is_available', true)
            ->with('workspace')
            ->orderBy('name')
            ->get();

        $timeSlots = $this->generateTimeSlots();

        return Inertia::render('Bookings/Availability', [
            'roomGroups' => $rooms->groupBy(fn ($r) => $r->workspace?->name ?? '')
                ->map(fn ($group, $workspace) => [
                    'workspace' => (string) $workspace,
                    'rooms' => $group->map(fn (Room $r) => ['id' => $r->id, 'label' => $r->name.' — '.$r->typeLabel()])->values()->all(),
                ])->values()->all(),
            'hasRooms' => $rooms->isNotEmpty(),
            'timeSlots' => $timeSlots,
            'prefRoom' => (string) $request->query('room_id', ''),
            'prefDate' => (string) $request->query('date', now()->format('Y-m-d')),
        ]);
    }

    /**
     * Check Availability for one day or a date range, for one room or all of
     * them: one request, one AvailabilityService::rangeReport() — per room,
     * per day, what's free and exactly which bookings block what.
     */
    public function availabilityRange(Request $request, AvailabilityService $availability): JsonResponse
    {
        $ownerId = TenantContext::id();

        $validator = Validator::make($request->all(), [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'party_size' => 'nullable|integer|min:1|max:999',
            'room_id' => 'nullable|integer',
        ], [
            'to.after_or_equal' => __('app.availability.errors.range_order'),
            'end_time.after' => __('app.availability.errors.time_order'),
        ]);
        $validator->after(function ($v) use ($request) {
            if ($v->errors()->isNotEmpty()) {
                return; // a malformed date is already reported — don't parse it
            }
            $from = $request->input('from');
            $to = $request->input('to') ?: $from;
            if ($from && $to && strtotime($to) >= strtotime($from)
                && Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > AvailabilityService::MAX_RANGE_DAYS) {
                $v->errors()->add('to', __('app.availability.errors.range_too_long', ['max' => AvailabilityService::MAX_RANGE_DAYS]));
            }
        });
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }
        $v = $validator->validated();

        $rooms = Room::where('owner_id', $ownerId)
            ->where('is_available', true)
            ->when($v['room_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->with(['workspace', 'owner'])
            ->orderBy('name')
            ->get();
        if (! empty($v['room_id']) && $rooms->isEmpty()) {
            abort(404); // another owner's room, or not bookable
        }

        $to = $v['to'] ?? $v['from'];

        return response()->json([
            'success' => true,
            'from' => $v['from'],
            'to' => $to,
            'days' => Carbon::parse($v['from'])->diffInDays(Carbon::parse($to)) + 1,
            'start_time' => $v['start_time'],
            'end_time' => $v['end_time'],
            'party_size' => (int) ($v['party_size'] ?? 1),
            'rooms' => $availability->rangeReport($rooms, $v['from'], $to, $v['start_time'], $v['end_time'], (int) ($v['party_size'] ?? 1)),
        ]);
    }

    public function checkAvailability(Request $request, AvailabilityService $availability, BusinessHoursService $businessHours, RoomPricingService $pricing): JsonResponse
    {
        $owner = TenantContext::user();

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'booking_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required',
            'booking_id' => 'nullable|exists:bookings,id',
            'party_size' => 'nullable|integer|min:1',
            'guest_count' => 'nullable|integer|min:1|max:999',
        ]);

        $room = Room::where('id', $validated['room_id'])
            ->where('owner_id', $owner->id)
            ->firstOrFail();

        $partySize = (int) ($validated['party_size'] ?? 1);

        // Room-type-aware: previously this always ran the exclusive-room
        // conflict check regardless of type, which was silently wrong for a
        // shared room (blind to currently-open SharedSessions, only ever
        // seeing historical/completed bookings). availabilityForRange() uses
        // Room::effectiveCapacity(), so an exclusive room still behaves
        // exactly as before (any overlap => unavailable) while a shared
        // room now gets a real seat-remaining answer instead of a wrong one.
        $remaining = $availability->availabilityForRange(
            $room,
            $validated['booking_date'],
            $validated['start_time'],
            $validated['end_time'],
            $validated['booking_id'] ?? null,
        );

        // This is the same read this preview's own store()/update() submit
        // will re-check — without it, a slot outside working hours would
        // show "Available" here and only fail once actually submitted.
        $withinHours = $businessHours->isWithinWorkingHours(
            $owner,
            $validated['booking_date'],
            $validated['start_time'],
            $validated['end_time'],
        );

        // An exclusive room is booked whole: it needs 1 "seat" however many
        // people come — the headcount only affects its price. A shared room
        // needs a seat per person.
        $seatsNeeded = $room->isShared() ? $partySize : 1;
        $isAvailable = $seatsNeeded <= $remaining && $withinHours;

        $quote = null;
        if ($isAvailable) {
            $people = $room->isShared() ? $partySize : (int) ($validated['guest_count'] ?? $partySize);
            $quote = $pricing->quoteBooking($room, $people, $validated['booking_date'], $validated['start_time'], $validated['end_time']);
        }

        return response()->json([
            'available' => $isAvailable,
            'remaining' => $remaining,
            'total_hours' => $quote?->totalHours(),
            'total_price' => $quote?->totalPrice,
            'price_per_hour' => $room->price_per_hour,
            'price_note' => $quote?->note,
            'price_summary' => $room->pricingSummary(),
        ]);
    }

    /**
     * Feeds the room-picker cards on the booking form: for every one of the
     * owner's rooms, the price at the currently-selected duration plus a
     * 3-state availability label — in one request instead of the picker
     * calling checkAvailability() once per room. Delegates to the exact same
     * RoomPricingService/AvailabilityService/BusinessHoursService calls
     * checkAvailability() itself uses; no pricing/availability math is
     * duplicated here. Read-only, no locks — safe to call on every keystroke.
     */
    /**
     * A member's usable hour packages for the "Use hour package" choice in the
     * booking form, the quick-booking popup and the start-session modal. Each
     * comes with its remaining time, expiry and — once the slot is known —
     * whether it can cover it (and if not, why). Never decides anything:
     * store()/update()/SharedSessionController re-check server-side.
     */
    public function packageOptions(Request $request, RoomPricingService $pricing, HourPackageService $packages): JsonResponse
    {
        $ownerId = TenantContext::id();

        $validated = $request->validate([
            'hotspot_user_id' => 'required|integer',
            'room_id' => 'nullable|integer',
            'booking_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'room_plan_id' => 'nullable|integer',
            'party_size' => 'nullable|integer|min:1',
            'guest_count' => 'nullable|integer|min:1|max:999',
            'booking_id' => 'nullable|integer',
            'context' => 'nullable|in:booking,session',
        ]);

        $member = HotspotUser::where('owner_id', $ownerId)->findOrFail($validated['hotspot_user_id']);
        $room = ! empty($validated['room_id']) ? Room::where('owner_id', $ownerId)->find($validated['room_id']) : null;
        $ignore = ! empty($validated['booking_id']) ? Booking::where('owner_id', $ownerId)->find($validated['booking_id']) : null;
        $isSession = ($validated['context'] ?? 'booking') === 'session';
        $date = $isSession ? today()->toDateString() : ($validated['booking_date'] ?? today()->toDateString());
        $partySize = (int) ($validated['party_size'] ?? 1);

        // Minutes needed: a session draws its actual time at close (so ≥ 1
        // minute is enough to open one); a booking needs its priced duration.
        $minutes = null;
        if ($isSession) {
            $minutes = 1;
        } elseif ($room && ! empty($validated['start_time']) && (! empty($validated['end_time']) || ! empty($validated['room_plan_id']))) {
            $people = $room->isShared() ? $partySize : (int) ($validated['guest_count'] ?? 1);
            [$quote] = $this->priceBooking($pricing, $room, $validated, $people);
            $minutes = $quote ? (int) ceil($quote->totalMinutes) : null;
        }

        $list = MemberPackage::where('owner_id', $ownerId)
            ->where('hotspot_user_id', $member->id)
            ->whereNull('cancelled_at')
            ->where(fn ($q) => $q->whereDate('expires_on', '>=', today())
                ->when($ignore?->member_package_id, fn ($q2, $id) => $q2->orWhere('id', $id)))
            ->orderBy('expires_on')
            ->get();

        $options = $list->map(function (MemberPackage $pkg) use ($packages, $room, $date, $minutes, $partySize, $ignore, $isSession) {
            $reason = null;
            if ($room && ! $isSession && $room->isShared()) {
                $reason = 'shared_booking';
            } elseif ($room && $minutes !== null) {
                $reason = $packages->eligibility($pkg, $room, $date, $minutes, $partySize, $ignore);
            } elseif ($pkg->cancelled_at || $pkg->expires_on->lt(today())) {
                $reason = 'expired';
            }
            $available = $packages->available($pkg, $ignore);

            return [
                'id' => $pkg->id,
                'name' => $pkg->name,
                'remaining_minutes' => $available,
                'remaining_label' => Duration::label($available),
                'total_label' => $pkg->totalLabel(),
                'expires_on' => $pkg->expires_on->toDateString(),
                'expires_label' => $pkg->expires_on->translatedFormat('M j, Y'),
                'eligible' => $reason === null && ($minutes !== null || $isSession) && $room !== null,
                'reason' => $reason ? __('app.packages.reasons.'.$reason) : null,
            ];
        })->values();

        return response()->json([
            'packages' => $options,
            'minutes' => $minutes,
            'minutes_label' => $minutes !== null && ! $isSession ? Duration::label($minutes) : null,
        ]);
    }

    public function roomOptions(Request $request, AvailabilityService $availability, BusinessHoursService $businessHours, RoomPricingService $pricing): JsonResponse
    {
        $owner = TenantContext::user();

        $validated = $request->validate([
            'booking_date' => 'required|date',
            'start_time' => 'nullable|required_with:end_time|date_format:H:i',
            'end_time' => 'nullable|required_with:start_time|date_format:H:i|after:start_time',
            'party_size' => 'nullable|integer|min:1',
            'guest_count' => 'nullable|integer|min:1|max:999',
            'booking_id' => 'nullable|exists:bookings,id',
        ]);

        // Date only (no time picked yet): just the day's Full Day window, so
        // the form can offer "Full day" before a start time exists.
        if (empty($validated['start_time'])) {
            $fullDay = $businessHours->fullDayWindow($owner, $validated['booking_date']);

            return response()->json([
                'rooms' => [],
                'full_day' => $fullDay ? ['start' => $fullDay['start'], 'end' => $fullDay['end']] : null,
            ]);
        }

        $bookingId = $validated['booking_id'] ?? null;
        $partySize = (int) ($validated['party_size'] ?? 1);
        $guestCount = (int) ($validated['guest_count'] ?? 1);

        // Same for every room at this date/time, so computed once rather
        // than once per room.
        $withinHours = $businessHours->isWithinWorkingHours(
            $owner,
            $validated['booking_date'],
            $validated['start_time'],
            $validated['end_time'],
        );

        $rooms = Room::where('owner_id', $owner->id)
            ->where('is_available', true)
            ->with(['workspace', 'plans', 'activePricingProfiles'])
            ->orderBy('name')
            ->get();

        $options = $rooms->map(function (Room $room) use ($availability, $validated, $bookingId, $partySize, $guestCount, $withinHours, $pricing, $businessHours, $owner) {
            $remaining = $availability->availabilityForRange(
                $room,
                $validated['booking_date'],
                $validated['start_time'],
                $validated['end_time'],
                $bookingId,
            );

            // Priced regardless of availability, so an unavailable card can
            // still show what it would have cost.
            $quote = $pricing->quoteBooking(
                $room,
                $room->isShared() ? $partySize : $guestCount,
                $validated['booking_date'],
                $validated['start_time'],
                $validated['end_time'],
            );

            if (! $withinHours) {
                $state = 'unavailable';
                $reason = 'outside_hours';
            } elseif ($partySize > $remaining) {
                $state = 'unavailable';
                $reason = $room->isShared() ? 'no_seats' : 'conflict';
            } else {
                // "Booked elsewhere today" — a whole-day overlap scan (00:00–
                // 23:59), reusing usedCapacity() rather than freeBusyForDay()
                // since only usedCapacity() can exclude the booking being
                // edited via $bookingId.
                $bookedElsewhere = $availability->usedCapacity(
                    $room, $validated['booking_date'], '00:00', '23:59', $bookingId,
                ) > 0;

                $state = $bookedElsewhere ? 'partial' : 'free';
                $reason = null;
            }

            return [
                'id' => $room->id,
                'name' => $room->name,
                'type' => $room->type,
                'type_label' => $room->typeLabel(),
                'capacity' => $room->capacity,
                'is_shared' => $room->isShared(),
                'price_per_hour' => (float) $room->price_per_hour,
                'price_per_hour_display' => Money::format((float) $room->price_per_hour),
                'price_summary' => $room->pricingSummary(),
                'pricing_model' => $room->pricingRules()->model,
                'uses_people' => $room->pricingRules()->usesPeople(),
                'plans' => $this->planOptions($room, $validated, $bookingId, $room->isShared() ? $partySize : $guestCount, $availability, $businessHours, $owner, $pricing),
                // Alternative hourly rates, each quoted server-side for this exact slot.
                'profiles' => $room->activePricingProfiles->map(function (RoomPricingProfile $p) use ($room, $pricing, $validated, $partySize, $guestCount) {
                    $q = $pricing->quoteBooking($room, $room->isShared() ? $partySize : $guestCount, $validated['booking_date'], $validated['start_time'], $validated['end_time'], $p);

                    return [
                        'id' => $p->id, 'name' => $p->name, 'rate_display' => $p->rateLabel(),
                        'total_price' => $q->totalPrice, 'total_price_display' => Money::format($q->totalPrice), 'note' => $q->note,
                    ];
                })->values()->all(),
                'total_hours' => $quote->totalHours(),
                'total_price' => $quote->totalPrice,
                'total_price_display' => Money::format($quote->totalPrice),
                'price_note' => $quote->note,
                'state' => $state,
                'reason' => $reason,
            ];
        });

        // The date's business day, so the form can offer a one-tap "Full day"
        // duration that lands exactly on the Full Day price.
        $fullDay = $businessHours->fullDayWindow($owner, $validated['booking_date']);

        return response()->json([
            'rooms' => $options->values(),
            'full_day' => $fullDay ? ['start' => $fullDay['start'], 'end' => $fullDay['end']] : null,
        ]);
    }

    /**
     * Adds to whatever has already been collected on this booking — never a
     * replacement value — so a booking that was under-deposited at creation
     * (or a shared-room booking, which never takes a deposit up front) can
     * still end up correctly counted once the rest of the cash comes in.
     * Without this, RevenueAnalyticsService::bookingRevenue() would
     * permanently under-report any booking that wasn't paid in full at
     * booking time, since nothing else in the app ever touches amount_paid
     * again.
     */
    public function recordPayment(Request $request, $id): RedirectResponse
    {
        $ownerId = TenantContext::id();

        $booking = Booking::where('owner_id', $ownerId)->findOrFail($id);

        if (in_array($booking->status, ['cancelled', 'no_show'], true)) {
            return back()->with('error', __('app.booking.payment.cannot_record_cancelled'));
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
        ]);

        $amount = round((float) $validated['amount'], 2);
        $balanceDue = $booking->balanceDue();

        if ($amount > $balanceDue) {
            return back()->with('error', __('app.booking.payment.exceeds_balance', ['balance' => number_format($balanceDue, 2)]));
        }

        DB::transaction(function () use ($id, $ownerId, $amount) {
            $locked = Booking::where('id', $id)->where('owner_id', $ownerId)->lockForUpdate()->firstOrFail();
            $newPaid = round((float) $locked->amount_paid + $amount, 2);

            $locked->update([
                'amount_paid' => $newPaid,
                'payment_status' => Booking::derivePaymentStatus($newPaid, $locked->netRoomCharge()),
            ]);
        });

        $booking->refresh();
        $this->activityLogger->log('booking.payment_recorded', $booking, "Recorded a payment of {$amount} for booking #{$booking->id}");

        return back()->with('success', __('app.booking.payment.recorded'));
    }

    /**
     * Attach a coupon to a still-editable (pending/confirmed, exclusive-room)
     * booking. Only computes/stores the discount fields on the booking (and
     * its sale, if any) — usage is recorded later, only once the booking
     * actually completes (see updateStatus()).
     */
    public function applyCoupon(Request $request, $id, CouponService $coupons): RedirectResponse|JsonResponse
    {
        $booking = Booking::where('owner_id', TenantContext::id())->with('room', 'sale')->findOrFail($id);

        $validated = $request->validate(['code' => 'required|string|max:40']);

        try {
            $breakdown = $coupons->attachToBooking($booking, $validated['code']);
        } catch (CouponRejectedException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'coupon' => $breakdown->toArray()]);
        }

        return back()->with('success', __('app.coupons.checkout.applied'));
    }

    public function removeCoupon(Request $request, $id, CouponService $coupons): RedirectResponse|JsonResponse
    {
        $booking = Booking::where('owner_id', TenantContext::id())->with('sale')->findOrFail($id);

        $coupons->detachFromBooking($booking);

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', __('app.coupons.checkout.removed'));
    }

    /**
     * Attach a product/service as a line item to this booking's sale.
     * Routed under feature:booking + feature:sales. Returns JSON when the
     * caller asks for it (the Active Sessions card's add-product modal, for
     * an in-progress exclusive-room booking) so one shared JS flow can drive
     * both this and SharedSessionController::addItem() without a full-page
     * reload; the standalone booking-detail page keeps its existing
     * synchronous back() redirect otherwise.
     */
    public function addItem(Request $request, $id, SalesService $sales, CouponService $coupons): RedirectResponse|JsonResponse
    {
        $ownerId = TenantContext::id();

        $booking = Booking::where('owner_id', $ownerId)->with('room')->findOrFail($id);

        if (! $booking->invoiceIsEditable()) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => __('app.sales.invoice_not_editable')], 422);
            }

            return back()->with('error', __('app.sales.invoice_not_editable'));
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1|max:1000',
        ]);

        // Scope the product to this owner — never trust a product id from another tenant.
        $product = Product::where('id', $validated['product_id'])
            ->where('owner_id', $ownerId)
            ->firstOrFail();

        // Stock is checked and taken server-side inside one transaction
        // (InventoryService); an inactive product or a shortfall adds nothing.
        $stockError = null;
        if (! $product->is_active) {
            $stockError = __('app.inventory.errors.inactive', ['name' => $product->name]);
        } elseif (! IdempotencyKey::claim($request, 'booking-item')) {
            // The same click delivered twice (retry, double submit) adds once.
            return $request->wantsJson()
                ? response()->json(['success' => true, 'duplicate' => true])
                : back();
        } else {
            try {
                DB::transaction(function () use ($sales, $booking, $product, $validated, &$stockError) {
                    // Re-checked on a fresh, locked row: a checkout that finalized
                    // this invoice a moment ago must win over a late "add".
                    $locked = Booking::whereKey($booking->id)->where('owner_id', $booking->owner_id)
                        ->with('room')->lockForUpdate()->firstOrFail();
                    if (! $locked->invoiceIsEditable()) {
                        $stockError = __('app.sales.invoice_not_editable');

                        return;
                    }
                    $sale = $sales->saleForBooking($locked);
                    $sales->addItem($sale, $product, (int) $validated['quantity']);
                });
            } catch (InsufficientStockException $e) {
                $stockError = $e->getMessage();
            }
        }
        if ($stockError) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $stockError], 422)
                : back()->with('error', $stockError);
        }

        $this->activityLogger->log('booking.item_added', $booking, "Added {$validated['quantity']}x {$product->name} to booking #{$booking->id}");

        // A coupon attached earlier may no longer be valid now the cart
        // changed (e.g. it dropped below minimum spend) — re-evaluate rather
        // than leave a stale discount on the books.
        $warning = $coupons->syncBooking($booking->fresh(['sale.items']));

        if ($request->wantsJson()) {
            return response()->json(array_filter(['success' => true, 'coupon_warning' => $warning]));
        }

        $redirect = back()->with('success', __('app.sales.item_added'));

        return $warning ? $redirect->with('warning', $warning) : $redirect;
    }

    /**
     * Remove a line item from this booking's sale. See addItem() for the
     * JSON-response rationale. The editability check is re-run against a
     * freshly locked row inside the transaction (not the pre-transaction
     * $booking) so this can't race a concurrent checkIn()/updateStatus()
     * that finalizes the booking between the outer lookup and this write.
     */
    public function removeItem(Request $request, $id, $itemId, SalesService $sales, CouponService $coupons): RedirectResponse|JsonResponse
    {
        $ownerId = TenantContext::id();

        $booking = Booking::where('owner_id', $ownerId)->with('room')->findOrFail($id);

        $errorMessage = null;

        DB::transaction(function () use ($booking, $ownerId, $itemId, $sales, &$errorMessage) {
            $locked = Booking::where('id', $booking->id)->where('owner_id', $ownerId)
                ->with(['sale', 'room'])->lockForUpdate()->firstOrFail();

            if (! $locked->invoiceIsEditable()) {
                $errorMessage = __('app.sales.invoice_not_editable');

                return;
            }

            if (! $locked->sale) {
                return;
            }

            $item = SaleItem::where('id', $itemId)->where('sale_id', $locked->sale->id)->firstOrFail();
            $sales->removeItem($item);

            $this->activityLogger->log('booking.item_removed', $locked, "Removed a line item from booking #{$locked->id}");
        });

        if ($errorMessage) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $errorMessage], 422)
                : back()->with('error', $errorMessage);
        }

        $warning = $coupons->syncBooking($booking->fresh(['sale.items']));

        if ($request->wantsJson()) {
            return response()->json(array_filter(['success' => true, 'coupon_warning' => $warning]));
        }

        $redirect = back()->with('success', __('app.sales.item_removed'));

        return $warning ? $redirect->with('warning', $warning) : $redirect;
    }

    /**
     * Change an existing line item's quantity (0 converges on removeItem()).
     * Same inside-transaction editability re-check as the hardened
     * removeItem() above, for the same race-safety reason.
     */
    public function updateItemQuantity(Request $request, $id, $itemId, SalesService $sales, CouponService $coupons): RedirectResponse|JsonResponse
    {
        $ownerId = TenantContext::id();

        $booking = Booking::where('owner_id', $ownerId)->with('room')->findOrFail($id);

        $validated = $request->validate([
            'quantity' => 'required|integer|min:0|max:1000',
        ]);

        $errorMessage = null;

        try {
            DB::transaction(function () use ($booking, $ownerId, $itemId, $validated, $sales, &$errorMessage) {
                $locked = Booking::where('id', $booking->id)->where('owner_id', $ownerId)
                    ->with(['sale', 'room'])->lockForUpdate()->firstOrFail();

                if (! $locked->invoiceIsEditable()) {
                    $errorMessage = __('app.sales.invoice_not_editable');

                    return;
                }

                if (! $locked->sale) {
                    $errorMessage = __('app.sales.item_not_found');

                    return;
                }

                $item = SaleItem::where('id', $itemId)->where('sale_id', $locked->sale->id)->firstOrFail();
                $sales->updateItemQuantity($item, (int) $validated['quantity']);
            });
        } catch (InsufficientStockException $e) {
            $errorMessage = $e->getMessage();
        }

        if ($errorMessage) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $errorMessage], 422)
                : back()->with('error', $errorMessage);
        }

        $this->activityLogger->log('booking.item_quantity_changed', $booking, "Set quantity to {$validated['quantity']} for a line item on booking #{$booking->id}");

        $warning = $coupons->syncBooking($booking->fresh(['sale.items']));

        if ($request->wantsJson()) {
            return response()->json(array_filter(['success' => true, 'coupon_warning' => $warning]));
        }

        $redirect = back()->with('success', __('app.sales.item_quantity_updated'));

        return $warning ? $redirect->with('warning', $warning) : $redirect;
    }
}
