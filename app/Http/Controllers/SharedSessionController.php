<?php

namespace App\Http\Controllers;

use App\Exceptions\CouponRejectedException;
use App\Exceptions\CouponUsageLimitExceededException;
use App\Exceptions\InsufficientPackageBalanceException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\SharedSessionCapacityExceededException;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\PackageUsage;
use App\Models\Product;
use App\Models\Room;
use App\Models\SaleItem;
use App\Models\SharedSession;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\BusinessHoursService;
use App\Services\CouponService;
use App\Services\HourPackageService;
use App\Services\RoomPricingService;
use App\Services\SalesService;
use App\Support\Duration;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SharedSessionController extends Controller
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private RoomPricingService $pricing,
    ) {}

    public function create(): View
    {
        $sharedRooms = Room::where('owner_id', TenantContext::id())
            ->where('type', 'shared')
            ->withSum(['sharedSessions as occupied_seats' => function ($q) {
                $q->where('status', 'open');
            }], 'party_size')
            ->with('workspace')
            ->get();

        return view('active-sessions.create', compact('sharedRooms'));
    }

    public function store(Request $request, AvailabilityService $availability, BusinessHoursService $businessHours, HourPackageService $packages): RedirectResponse
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'hotspot_user_id' => 'required|exists:hotspot_users,id',
            'session_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'party_size' => 'nullable|integer|min:1',
            'member_package_id' => 'nullable|integer',
            'room_pricing_profile_id' => 'nullable|integer',
        ]);

        $owner = TenantContext::user();
        $ownerId = $owner->id;
        $partySize = (int) ($request->input('party_size') ?: 1);

        if (! $businessHours->isOpenAt($owner, Carbon::parse($request->session_date.' '.$request->start_time))) {
            return back()->withInput()->with('error', __('app.session.outside_working_hours'));
        }

        $user = HotspotUser::where('id', $request->hotspot_user_id)
            ->where('owner_id', $ownerId)
            ->firstOrFail();

        // Optional hour package: chosen now, drawn at close by the actual
        // elapsed time. Covers the member's own seat only (party size 1).
        // Optional pricing profile: the session bills per hour at its rate
        // (billing unit + grace buffer still from the room).
        $profile = null;
        if ($request->filled('room_pricing_profile_id')) {
            $profileRoom = Room::where('id', $request->room_id)->where('owner_id', $ownerId)->firstOrFail();
            try {
                $profile = $this->pricing->resolveProfile($profileRoom, (int) $request->input('room_pricing_profile_id'));
            } catch (ModelNotFoundException) {
                return back()->withInput()->with('error', __('app.pricing_profiles.errors.not_available'));
            }
        }

        $packageId = null;
        if ($request->filled('member_package_id')) {
            $package = MemberPackage::where('owner_id', $ownerId)
                ->where('hotspot_user_id', $user->id)
                ->whereKey($request->input('member_package_id'))
                ->firstOrFail();
            $packageRoom = Room::where('id', $request->room_id)->where('owner_id', $ownerId)->firstOrFail();
            if ($reason = $packages->eligibility($package, $packageRoom, $request->session_date, 1, $partySize)) {
                return back()->withInput()->with('error', __('app.packages.reasons.'.$reason));
            }
            $packageId = $package->id;
        }

        // The capacity check and the insert must happen atomically: two staff
        // opening large parties into the room's last few free seats at the same
        // moment must not both pass the check and jointly overbook it.
        // lockForUpdate() genuinely serializes this on MySQL (production); it's a
        // no-op on SQLite (dev/test) — the post-write exceedsCapacity()-style
        // re-check below is the second, engine-independent layer this doesn't
        // rely on alone, mirroring BookingController::store()'s identical
        // defense-in-depth pattern.
        try {
            [$roomName, $error] = DB::transaction(function () use ($request, $ownerId, $user, $partySize, $availability, $packageId, $profile) {
                $room = Room::where('id', $request->room_id)
                    ->where('owner_id', $ownerId)
                    ->where('type', 'shared')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($partySize > $room->effectiveCapacity()) {
                    return [null, "This room only seats {$room->capacity} people."];
                }

                // The unified "right now" formula — capacity already claimed
                // by overlapping advance Bookings AND other open sessions,
                // not just open sessions in isolation. Room::availableSharedSlots()
                // is blind to the bookings table entirely and is no longer
                // safe to gate a write with now that shared rooms can be
                // advance-booked (Phase 5).
                $available = $availability->availableNow($room);
                if ($partySize > $available) {
                    return [null, "Only {$available} of {$room->capacity} seats available right now."];
                }

                $existing = SharedSession::where('room_id', $room->id)
                    ->where('hotspot_user_id', $user->id)
                    ->where('status', 'open')
                    ->exists();

                if ($existing) {
                    return [null, "{$user->name} already has an open session in this room."];
                }

                // Never in the future: a start time ahead of the server clock
                // (wrong device clock/timezone) would freeze the timer at 0.
                $openedAt = Carbon::parse($request->session_date.' '.$request->start_time)->min(now());

                // Snapshotted from the room now, at open time — never read live
                // from the room again for this session. Without this, an Owner
                // changing the room's billing_unit or price_per_hour while this
                // session is already open would silently change its final bill.
                SharedSession::create([
                    'owner_id' => $ownerId,
                    'room_id' => $room->id,
                    'hotspot_user_id' => $user->id,
                    'party_size' => $partySize,
                    'session_date' => $request->session_date,
                    'start_time' => $request->start_time,
                    'opened_at' => $openedAt,
                    'status' => 'open',
                    'billing_unit' => $room->billing_unit,
                    'billing_buffer_minutes' => (int) $room->billing_buffer_minutes,
                    'billed_price_per_hour' => $profile ? $profile->price_per_hour : $room->price_per_hour,
                    'pricing_snapshot' => $this->pricing->snapshotFor($room, $profile),
                    'room_pricing_profile_id' => $profile?->id,
                    'pricing_profile_name' => $profile?->name,
                    'member_package_id' => $packageId,
                ]);

                if ($availability->usedCapacityNow($room) > $room->effectiveCapacity()) {
                    throw new SharedSessionCapacityExceededException;
                }

                return [$room->name, null];
            });
        } catch (SharedSessionCapacityExceededException) {
            return back()->withInput()->with('error', 'This room is already full. Please try again.');
        }

        if ($error) {
            return back()->withInput()->with('error', $error);
        }

        $session = SharedSession::where('owner_id', $ownerId)->where('room_id', $request->room_id)
            ->where('hotspot_user_id', $user->id)->where('status', 'open')->latest('id')->first();
        if ($session) {
            $this->activityLogger->log('shared_session.opened', $session, "Opened session for {$user->name} in {$roomName}");
        }

        return redirect()->route('active-sessions.index')
            ->with('success', "Session opened for {$user->name} in {$roomName}.");
    }

    public function closePreview(int $sessionId, Request $request, CouponService $coupons, HourPackageService $packages): JsonResponse
    {
        $session = SharedSession::where('id', $sessionId)
            ->where('owner_id', TenantContext::id())
            ->where('status', 'open')
            ->with(['room', 'hotspotUser', 'sale.items', 'memberPackage'])
            ->firstOrFail();

        $closedAt = now();
        $quote = $this->pricing->quoteSession($session, $closedAt);

        $duration = $this->formatMinutes($quote->totalMinutes);

        // Only shown when the billed time actually differs from the time
        // used (block billing rounded up, or a package longer than the time
        // used) — a session billed exactly what it used has nothing to clarify.
        $billedDuration = abs($quote->totalMinutes - $quote->billedMinutes) > 0.01
            ? $this->formatMinutes($quote->billedMinutes)
            : null;

        $itemsTotal = (float) ($session->sale?->total ?? 0);
        $subtotal = round($quote->totalPrice + $itemsTotal, 2);
        $grandTotal = $subtotal;

        // Preview-only: evaluate() never writes anything. close() re-runs the
        // exact same evaluate() call inside its own transaction, so the two
        // can never disagree on what will actually be charged.
        // Hour package chosen at open: covers the room time when it still can,
        // otherwise the session falls back to normal billing (same rule as close()).
        $packagePayload = null;
        if ($pkg = $session->memberPackage) {
            $minutes = Duration::packageMinutes($quote->totalMinutes);
            $covers = $packages->eligibility($pkg, $session->room, $session->session_date, $minutes, $session->party_size) === null;
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
                $breakdown = $coupons->evaluate($coupon, $coupons->cartForSession($session, $quote), $session->hotspot_user_id);
                $couponPayload = $breakdown->toArray();
                $grandTotal = $breakdown->total();
            } catch (CouponRejectedException $e) {
                $couponError = $e->getMessage();
            }
        }

        return response()->json([
            'session_id' => $session->id,
            'user_name' => $session->hotspotUser->name,
            'user_phone' => $session->hotspotUser->phone,
            'room_name' => $session->room->name,
            'party_size' => $session->party_size,
            'start_time' => $session->opened_at->format('h:i A'),
            'end_time' => $closedAt->format('h:i A'),
            'closed_at_datetime' => $closedAt->toDateTimeString(),
            'duration' => $duration,
            'billed_duration' => $billedDuration,
            'total_minutes' => $quote->totalMinutes,
            'price_per_hour' => number_format($quote->ratePerHour, 2),
            'pricing_note' => $quote->note,
            'total_price' => number_format($quote->totalPrice, 2),
            'total_price_raw' => $quote->totalPrice,
            'items' => $this->itemsPayload($session),
            'items_total' => number_format($itemsTotal, 2),
            'subtotal' => number_format($subtotal, 2),
            'grand_total' => number_format($grandTotal, 2),
            'coupon' => $couponPayload,
            'coupon_error' => $couponError,
            'package' => $packagePayload,
        ]);
    }

    /** Add a product to the session's running tab. Routed under feature:booking + feature:sales. */
    public function addItem(Request $request, int $sessionId, SalesService $sales): JsonResponse
    {
        $ownerId = TenantContext::id();

        $session = SharedSession::where('id', $sessionId)
            ->where('owner_id', $ownerId)
            ->where('status', 'open')
            ->firstOrFail();

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1|max:1000',
        ]);

        // Scope the product to this owner — never trust a product id from another tenant.
        $product = Product::where('id', $validated['product_id'])
            ->where('owner_id', $ownerId)
            ->firstOrFail();

        if (! $product->is_active) {
            return response()->json(['success' => false, 'message' => __('app.inventory.errors.inactive', ['name' => $product->name])], 422);
        }

        // Stock is checked and taken server-side inside the same transaction
        // (InventoryService); a shortfall rolls the whole add back.
        try {
            DB::transaction(function () use ($sales, $session, $product, $validated) {
                $sale = $sales->saleForSharedSession($session);
                $sales->addItem($sale, $product, (int) $validated['quantity']);
            });
        } catch (InsufficientStockException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->activityLogger->log('shared_session.item_added', $session, "Added {$validated['quantity']}x {$product->name} to session #{$session->id}");

        return response()->json(['success' => true]);
    }

    /**
     * Remove a line item from the session's running tab. The status='open'
     * check is re-run inside the transaction against a fresh row (not just
     * before it), so this can't race a concurrent close() — close()'s own
     * atomic status-flip is the serializing side of that race (see its
     * docblock on why a plain re-SELECT here is enough on SQLite too).
     */
    public function removeItem(int $sessionId, int $itemId, SalesService $sales): JsonResponse
    {
        $ownerId = TenantContext::id();

        $errorMessage = null;

        DB::transaction(function () use ($sessionId, $ownerId, $itemId, $sales, &$errorMessage) {
            $session = SharedSession::where('id', $sessionId)->where('owner_id', $ownerId)
                ->where('status', 'open')->with('sale')->first();

            if (! $session) {
                $errorMessage = __('app.sales.invoice_not_editable');

                return;
            }

            if (! $session->sale) {
                return;
            }

            $item = SaleItem::where('id', $itemId)->where('sale_id', $session->sale->id)->firstOrFail();
            $sales->removeItem($item);

            $this->activityLogger->log('shared_session.item_removed', $session, "Removed a line item from session #{$session->id}");
        });

        if ($errorMessage) {
            return response()->json(['success' => false, 'message' => $errorMessage], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Change an existing line item's quantity on the session's running tab
     * (0 converges on removeItem()). Same inside-transaction status='open'
     * re-check as the hardened removeItem() above.
     */
    public function updateItemQuantity(Request $request, int $sessionId, int $itemId, SalesService $sales): JsonResponse
    {
        $ownerId = TenantContext::id();

        $validated = $request->validate([
            'quantity' => 'required|integer|min:0|max:1000',
        ]);

        $errorMessage = null;

        try {
            DB::transaction(function () use ($sessionId, $ownerId, $itemId, $validated, $sales, &$errorMessage) {
                $session = SharedSession::where('id', $sessionId)->where('owner_id', $ownerId)
                    ->where('status', 'open')->with('sale')->first();

                if (! $session) {
                    $errorMessage = __('app.sales.invoice_not_editable');

                    return;
                }

                if (! $session->sale) {
                    $errorMessage = __('app.sales.item_not_found');

                    return;
                }

                $item = SaleItem::where('id', $itemId)->where('sale_id', $session->sale->id)->firstOrFail();
                $sales->updateItemQuantity($item, (int) $validated['quantity']);

                $this->activityLogger->log('shared_session.item_quantity_changed', $session, "Set quantity to {$validated['quantity']} for a line item on session #{$session->id}");
            });
        } catch (InsufficientStockException $e) {
            $errorMessage = $e->getMessage();
        }

        if ($errorMessage) {
            return response()->json(['success' => false, 'message' => $errorMessage], 422);
        }

        return response()->json(['success' => true]);
    }

    /** 'Xh Ym' for a fractional-minutes value, used by both the actual and billed duration strings. */
    private function formatMinutes(float $minutes): string
    {
        $h = intdiv((int) $minutes, 60);
        $m = (int) $minutes % 60;

        return ($h > 0 ? $h.'h ' : '').$m.'m';
    }

    /** Serialise the tab's line items for the close modal. */
    private function itemsPayload(SharedSession $session): array
    {
        if (! $session->sale) {
            return [];
        }

        return $session->sale->items->map(fn (SaleItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit_price' => number_format($item->unit_price, 2),
            'line_total' => number_format($item->line_total, 2),
        ])->all();
    }

    /**
     * Close an open session. Money is always computed here, server-side, from
     * opened_at → now(): the client is never trusted for total_minutes/total_price
     * (it previously posted its own preview-time numbers straight into the
     * booking — a browser should never be the source of truth for a charge).
     *
     * The status flip is a single atomic UPDATE guarded by WHERE status='open',
     * checked for affected rows, before anything else runs. That closes the
     * double-close race (two concurrent clicks/requests): only one request can
     * ever see affected=1 and proceed; the other sees 0 and is rejected. This
     * works identically on SQLite (dev/test) and MySQL (production) — a single
     * UPDATE statement is atomic on both — unlike lockForUpdate(), which SQLite
     * does not honor.
     */
    public function close(Request $request, int $sessionId, SalesService $sales, CouponService $coupons, HourPackageService $packages): JsonResponse
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
            return DB::transaction(function () use ($sessionId, $ownerId, $closedAt, $sales, $coupons, $couponCode, $packages) {
                $claimed = SharedSession::where('id', $sessionId)
                    ->where('owner_id', $ownerId)
                    ->where('status', 'open')
                    ->update(['status' => 'closed', 'closed_at' => $closedAt]);

                if ($claimed === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => __('app.session.already_closed'),
                    ], 409);
                }

                $session = SharedSession::where('id', $sessionId)
                    ->where('owner_id', $ownerId)
                    ->with(['room', 'hotspotUser', 'sale'])
                    ->firstOrFail();

                // Same service (and the same snapshotted pricing) as
                // closePreview() — the two must never disagree on what a session
                // is about to cost.
                $quote = $this->pricing->quoteSession($session, $closedAt);
                $totalHours = $quote->billedHours();

                $booking = Booking::create([
                    'owner_id' => $ownerId,
                    'room_id' => $session->room_id,
                    'room_pricing_profile_id' => $session->room_pricing_profile_id,
                    'pricing_profile_name' => $session->pricing_profile_name,
                    'hotspot_user_id' => $session->hotspot_user_id,
                    'party_size' => $session->party_size,
                    'booking_date' => $session->session_date,
                    'start_time' => $session->start_time,
                    'end_time' => $closedAt->format('H:i'),
                    'price_per_hour' => $quote->ratePerHour,
                    'total_hours' => $totalHours,
                    'total_price' => $quote->totalPrice,
                    'pricing_note' => $quote->note,
                    // A closed shared/walk-in session is cash collected at the
                    // register right now — always fully paid (net of any
                    // coupon discount, applied below). Without this, the
                    // Financials revenue switch to amount_paid would silently
                    // zero out every walk-in session's revenue.
                    'amount_paid' => $quote->totalPrice,
                    'payment_status' => Booking::PAYMENT_PAID,
                    'status' => 'completed',
                    'notes' => 'Auto-created from shared session.',
                ]);

                $session->update([
                    'total_minutes' => $quote->totalMinutes,
                    'total_price' => $quote->totalPrice,
                    'booking_id' => $booking->id,
                ]);

                // Move the running tab (if any) onto the booking, keeping its line items.
                if ($session->sale) {
                    $sales->transferToBooking($session->sale, $booking);
                }

                // Hour package chosen at open: draw the actual elapsed minutes.
                // Not enough left (or no longer valid) → normal billing as above.
                $coveredByPackage = false;
                $pkg = $session->member_package_id
                    ? MemberPackage::where('owner_id', $ownerId)->find($session->member_package_id)
                    : null;
                if ($pkg) {
                    $minutes = Duration::packageMinutes($quote->totalMinutes);
                    $covered = null;
                    if ($packages->eligibility($pkg, $session->room, $session->session_date, $minutes, $session->party_size) === null) {
                        try {
                            $covered = $packages->consume($pkg, $minutes, PackageUsage::SESSION_USAGE, $booking, $session);
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
                        $session->update(['total_price' => $value]);
                    } else {
                        $booking->update(['notes' => $booking->notes.' '.__('app.packages.session_fallback', ['remaining' => $pkg->fresh()->remainingLabel()])]);
                    }
                }

                // A coupon rejection here throws and rolls back the whole
                // transaction — the session re-opens (the atomic claim above
                // is undone too), and no Booking or usage row is left behind.
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

                $this->activityLogger->log('shared_session.closed', $session, "Closed session #{$session->id}, total ج.م ".number_format($grandTotal, 2));

                return response()->json([
                    'success' => true,
                    'message' => 'Session closed. Total: ج.م '.number_format($grandTotal, 2),
                    'booking_id' => $booking->id,
                ]);
            });
        } catch (CouponRejectedException|CouponUsageLimitExceededException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
