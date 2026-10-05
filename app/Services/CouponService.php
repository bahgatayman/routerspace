<?php

namespace App\Services;

use App\Exceptions\CouponRejectedException;
use App\Exceptions\CouponUsageLimitExceededException;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\SharedSession;
use App\Support\Coupons\CouponBreakdown;
use App\Support\Coupons\CouponCart;
use App\Support\Pricing\PriceQuote;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Server-side-only coupon validation, discount math, and redemption. Never
 * recomputes a room or product price itself — always discounts on top of an
 * already-computed total (RoomPricingService's quote, or a Sale's own line
 * items). evaluate() is pure/no-writes and shared by every preview and
 * completion call site, so a preview and the eventual charge can never
 * disagree. redeemForBooking() is the only writer of coupon_usages, and must
 * be called inside the caller's own completion transaction (see
 * BookingController::updateStatus() / SharedSessionController::close()).
 */
class CouponService
{
    public function __construct(private SalesService $sales) {}

    public function normalizeCode(?string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim((string) $code)));
    }

    /**
     * Owner-scoped lookup. A wrong-owner code and a missing code produce the
     * identical rejection, so a code's existence for another owner is never
     * leaked through the error message.
     */
    public function find(int $ownerId, string $code): Coupon
    {
        $normalized = $this->normalizeCode($code);

        $coupon = $normalized !== ''
            ? Coupon::where('owner_id', $ownerId)->where('code', $normalized)->first()
            : null;

        if (! $coupon) {
            throw new CouponRejectedException('not_found');
        }

        return $coupon;
    }

    public function cartForBooking(Booking $booking): CouponCart
    {
        $booking->loadMissing('sale.items');

        return new CouponCart($booking->room_id, (float) $booking->total_price, $this->productLines($booking->sale));
    }

    public function cartForSession(SharedSession $session, PriceQuote $quote): CouponCart
    {
        $session->loadMissing('sale.items');

        return new CouponCart($session->room_id, $quote->totalPrice, $this->productLines($session->sale));
    }

    /**
     * Like cartForBooking(), but for a still-open Booking (Open Session):
     * total_price is still 0 until checkout, so the quote just computed by
     * RoomPricingService::quoteOpenBooking() is the real cart total, not the
     * booking's own (not-yet-final) column.
     */
    public function cartForOpenBooking(Booking $booking, PriceQuote $quote): CouponCart
    {
        $booking->loadMissing('sale.items');

        return new CouponCart($booking->room_id, $quote->totalPrice, $this->productLines($booking->sale));
    }

    /** @return array<int, array{product_id: ?int, amount: float}> */
    private function productLines($sale): array
    {
        if (! $sale) {
            return [];
        }

        return $sale->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'amount' => (float) $item->line_total,
        ])->all();
    }

    /**
     * Pure validation + discount calculation — no database writes. Every
     * check the spec requires, in order, each throwing a translated
     * CouponRejectedException. $excludeBookingId lets a booking that already
     * holds this coupon re-evaluate itself without counting its own
     * (not-yet-written, or already-written) usage row against the limits.
     */
    public function evaluate(Coupon $coupon, CouponCart $cart, ?int $hotspotUserId = null, ?int $excludeBookingId = null, ?Carbon $at = null): CouponBreakdown
    {
        $at ??= now();

        if (! $coupon->is_active) {
            throw new CouponRejectedException('inactive');
        }
        if ($coupon->starts_at !== null && $coupon->starts_at->gt($at)) {
            throw new CouponRejectedException('scheduled');
        }
        if ($coupon->expires_at !== null && $coupon->expires_at->lt($at)) {
            throw new CouponRejectedException('expired');
        }

        if ($coupon->usage_limit !== null) {
            $used = $coupon->usages()->when($excludeBookingId, fn ($q) => $q->where('booking_id', '!=', $excludeBookingId))->count();
            if ($used >= $coupon->usage_limit) {
                throw new CouponRejectedException('limit_reached');
            }
        }

        if ($hotspotUserId !== null && $coupon->per_customer_limit !== null) {
            $usedByCustomer = $coupon->usages()
                ->where('hotspot_user_id', $hotspotUserId)
                ->when($excludeBookingId, fn ($q) => $q->where('booking_id', '!=', $excludeBookingId))
                ->count();
            if ($usedByCustomer >= $coupon->per_customer_limit) {
                throw new CouponRejectedException('customer_limit_reached');
            }
        }

        $subtotal = $cart->subtotal();
        if ($coupon->minimum_spend !== null && $subtotal < (float) $coupon->minimum_spend) {
            throw new CouponRejectedException('minimum_spend', ['amount' => number_format((float) $coupon->minimum_spend, 2)]);
        }

        $eligibleRoom = $cart->roomEligible($coupon);
        $eligibleProducts = $cart->productsEligible($coupon);
        $eligible = round($eligibleRoom + $eligibleProducts, 2);

        if ($eligible <= 0) {
            throw new CouponRejectedException('out_of_scope');
        }

        $discount = $coupon->discount_type === Coupon::TYPE_PERCENTAGE
            ? round($eligible * (float) $coupon->discount_value / 100, 2)
            : min((float) $coupon->discount_value, $eligible);
        $discount = round($discount, 2);

        // Proportional split so the two parts always sum exactly to $discount.
        if ($eligibleRoom > 0 && $eligibleProducts > 0) {
            $roomDiscount = round($discount * $eligibleRoom / $eligible, 2);
            $productDiscount = round($discount - $roomDiscount, 2);
        } elseif ($eligibleRoom > 0) {
            $roomDiscount = $discount;
            $productDiscount = 0.0;
        } else {
            $roomDiscount = 0.0;
            $productDiscount = $discount;
        }

        return new CouponBreakdown($coupon->code, $subtotal, $eligibleRoom, $eligibleProducts, $discount, $roomDiscount, $productDiscount);
    }

    /**
     * Attach a coupon to a still-editable (pending/confirmed, exclusive-room)
     * booking. Only computes/stores the discount fields — usage is recorded
     * later, only at actual completion (see redeemForBooking()).
     */
    public function attachToBooking(Booking $booking, string $code): CouponBreakdown
    {
        if ($booking->room?->isShared()) {
            throw new CouponRejectedException('shared_room');
        }
        if ($booking->payment_method === Booking::METHOD_PACKAGE) {
            throw new CouponRejectedException('package_booking');
        }
        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            throw new CouponRejectedException('not_editable');
        }

        $coupon = $this->find($booking->owner_id, $code);
        $breakdown = $this->evaluate($coupon, $this->cartForBooking($booking), $booking->hotspot_user_id, excludeBookingId: $booking->id);

        $net = round((float) $booking->total_price - $breakdown->roomDiscount, 2);
        if ((float) $booking->amount_paid > $net) {
            throw new CouponRejectedException('exceeds_paid');
        }

        DB::transaction(function () use ($booking, $coupon, $breakdown, $net) {
            $booking->update([
                'coupon_id' => $coupon->id,
                'discount_total' => $breakdown->roomDiscount,
                'payment_status' => Booking::derivePaymentStatus((float) $booking->amount_paid, $net),
            ]);

            if ($booking->sale) {
                $booking->sale->update(['discount_total' => $breakdown->productDiscount]);
                $this->sales->recalculate($booking->sale);
            }
        });

        return $breakdown;
    }

    public function detachFromBooking(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $booking->update([
                'coupon_id' => null,
                'discount_total' => 0,
                'payment_status' => Booking::derivePaymentStatus((float) $booking->amount_paid, (float) $booking->total_price),
            ]);

            if ($booking->sale && (float) $booking->sale->discount_total > 0) {
                $booking->sale->update(['discount_total' => 0]);
                $this->sales->recalculate($booking->sale);
            }
        });
    }

    /**
     * Re-evaluates an already-attached coupon after the booking's totals
     * changed (a product was added/removed, the room/time was edited).
     * Detaches and returns a user-facing warning if the coupon is no longer
     * valid, rather than silently keeping a stale discount — never leaves a
     * discount_total on the books that the current evaluate() wouldn't grant.
     */
    public function syncBooking(Booking $booking): ?string
    {
        if (! $booking->coupon_id) {
            return null;
        }

        // Hours from a package can't be discounted (V1) — drop the coupon.
        if ($booking->payment_method === Booking::METHOD_PACKAGE) {
            $this->detachFromBooking($booking);

            return __('app.coupons.errors.package_booking');
        }

        $coupon = Coupon::where('owner_id', $booking->owner_id)->find($booking->coupon_id);
        if (! $coupon) {
            $this->detachFromBooking($booking);

            return __('app.coupons.checkout.scope_deactivated');
        }

        try {
            $breakdown = $this->evaluate($coupon, $this->cartForBooking($booking), $booking->hotspot_user_id, excludeBookingId: $booking->id);
            $net = round((float) $booking->total_price - $breakdown->roomDiscount, 2);
            if ((float) $booking->amount_paid > $net) {
                throw new CouponRejectedException('exceeds_paid');
            }
        } catch (CouponRejectedException $e) {
            $this->detachFromBooking($booking);

            return $e->getMessage();
        }

        DB::transaction(function () use ($booking, $breakdown, $net) {
            $booking->update([
                'discount_total' => $breakdown->roomDiscount,
                'payment_status' => Booking::derivePaymentStatus((float) $booking->amount_paid, $net),
            ]);

            if ($booking->sale) {
                $booking->sale->update(['discount_total' => $breakdown->productDiscount]);
                $this->sales->recalculate($booking->sale);
            }
        });

        return null;
    }

    /**
     * The only writer of coupon_usages. Must run inside the CALLER's own
     * completion transaction (after the caller has already atomically
     * claimed completion) so a coupon rejection rolls back the whole
     * completion, not just this piece. Idempotent: a booking that already
     * has a usage row is returned as-is, never double-recorded.
     */
    public function redeemForBooking(Booking $booking): ?CouponUsage
    {
        if (! $booking->coupon_id) {
            return null;
        }

        $existing = CouponUsage::where('booking_id', $booking->id)->first();
        if ($existing) {
            return $existing;
        }

        // Real on MySQL, a documented no-op on SQLite — the post-write count
        // re-check below is what actually guarantees correctness under a race.
        $coupon = Coupon::where('id', $booking->coupon_id)->where('owner_id', $booking->owner_id)->lockForUpdate()->first();
        if (! $coupon) {
            $booking->update(['coupon_id' => null, 'discount_total' => 0]);

            return null;
        }

        $breakdown = $this->evaluate($coupon, $this->cartForBooking($booking), $booking->hotspot_user_id, excludeBookingId: $booking->id);

        $net = round((float) $booking->total_price - $breakdown->roomDiscount, 2);
        $booking->update([
            'discount_total' => $breakdown->roomDiscount,
            'payment_status' => Booking::derivePaymentStatus((float) $booking->amount_paid, $net),
        ]);

        if ($booking->sale) {
            $booking->sale->update(['discount_total' => $breakdown->productDiscount]);
            $this->sales->recalculate($booking->sale);
        }

        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'owner_id' => $booking->owner_id,
            'booking_id' => $booking->id,
            'hotspot_user_id' => $booking->hotspot_user_id,
            'original_amount' => $breakdown->eligibleAmount(),
            'discount_amount' => $breakdown->discount,
            'final_amount' => round($breakdown->eligibleAmount() - $breakdown->discount, 2),
            'room_discount' => $breakdown->roomDiscount,
            'product_discount' => $breakdown->productDiscount,
            'used_at' => now(),
        ]);

        if ($coupon->usage_limit !== null && $coupon->usages()->count() > $coupon->usage_limit) {
            throw new CouponUsageLimitExceededException('limit_reached');
        }

        if ($booking->hotspot_user_id && $coupon->per_customer_limit !== null) {
            $customerCount = $coupon->usages()->where('hotspot_user_id', $booking->hotspot_user_id)->count();
            if ($customerCount > $coupon->per_customer_limit) {
                throw new CouponUsageLimitExceededException('customer_limit_reached');
            }
        }

        return $usage;
    }

    /**
     * Booking deleted: undo an already-redeemed coupon. No counterpart ever
     * existed for redeemForBooking() before this — detachFromBooking() is a
     * different operation (it only clears a still-pending, not-yet-redeemed
     * attachment). Every usage-limit/per-customer-limit check is a live
     * usages()->count() query (see evaluate() above), so deleting the row is
     * the entire reversal — nothing else to decrement. Idempotent no-op for
     * a booking with no coupon or no usage row.
     */
    public function releaseBooking(Booking $booking): void
    {
        CouponUsage::where('booking_id', $booking->id)->first()?->delete();
    }
}
