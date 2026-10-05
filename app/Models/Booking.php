<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory;

    /**
     * Grace period after start_time before an unclaimed shared-room
     * reservation counts as a no-show (Phase 5). Checked live by
     * isPastNoShowGrace() wherever correctness matters — the scheduled
     * sweep that flips status to 'no_show' is a cleanup pass, not the
     * source of truth, since a booking can be past-grace for a while
     * before the sweep next runs.
     */
    public const NO_SHOW_GRACE_MINUTES = 30;

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_PAID = 'paid';

    protected $fillable = [
        'owner_id',
        'room_id',
        'room_plan_id',
        'room_pricing_profile_id',
        'pricing_profile_name',
        'coupon_id',
        'hotspot_user_id',
        'party_size',
        'guest_count',
        'checked_in_party_size',
        'booking_date',
        'start_time',
        'end_time',
        'price_per_hour',
        'total_hours',
        'total_price',
        'discount_total',
        'pricing_note',
        'amount_paid',
        'payment_status',
        'payment_method',
        'member_package_id',
        'status',
        'notes',
        'billing_unit',
        'billing_buffer_minutes',
        'pricing_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'price_per_hour' => 'decimal:2',
            'total_hours' => 'decimal:2',
            'total_price' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'party_size' => 'integer',
            'guest_count' => 'integer',
            'checked_in_party_size' => 'integer',
            'billing_buffer_minutes' => 'integer',
            'pricing_snapshot' => 'array',
        ];
    }

    /**
     * The single place payment_status is computed from an amount/total pair.
     * Called explicitly at each write site (store/update/recordPayment) —
     * this codebase has no model events/observers anywhere, so a derived
     * value stays a plain static helper rather than a mutator or booted().
     */
    public static function derivePaymentStatus(float $paid, float $total): string
    {
        $paid = round($paid, 2);
        $total = round($total, 2);

        if ($paid >= $total) {
            return self::PAYMENT_PAID;
        }

        return $paid > 0 ? self::PAYMENT_PARTIAL : self::PAYMENT_UNPAID;
    }

    /**
     * total_price stays the gross room charge exactly as RoomPricingService
     * returned it — never rewritten. discount_total is a coupon's room-side
     * discount on top of it (0 when no coupon is attached). This is what
     * payment/balance math means by "the room charge" from here on.
     */
    public function netRoomCharge(): float
    {
        return max(0, (float) $this->total_price - (float) $this->discount_total);
    }

    /**
     * What's left to collect on the (net-of-coupon) room charge. Deliberately
     * ignores any attached Sale (products) — those are already counted
     * separately in RevenueAnalyticsService::saleRevenue(), so folding them in
     * here would double-count them against this same balance.
     */
    public function balanceDue(): float
    {
        return max(0, $this->netRoomCharge() - (float) $this->amount_paid);
    }

    public const METHOD_PACKAGE = 'package';

    /** Paid with prepaid hours (HourPackageService) instead of cash. */
    public function isPackageCovered(): bool
    {
        return $this->payment_method === self::METHOD_PACKAGE && $this->member_package_id !== null;
    }

    public function memberPackage(): BelongsTo
    {
        return $this->belongsTo(MemberPackage::class);
    }

    public function paymentStatusLabel(): string
    {
        if ($this->payment_method === self::METHOD_PACKAGE) {
            return __('app.packages.covered');
        }

        return match ($this->payment_status) {
            self::PAYMENT_PAID => __('app.booking.payment.status_paid'),
            self::PAYMENT_PARTIAL => __('app.booking.payment.status_partial'),
            default => __('app.booking.payment.status_unpaid'),
        };
    }

    /**
     * Semantic tone for <x-ui.badge :tone="..."> — the single source of
     * truth other views should call rather than re-deriving their own
     * mapping (see statusBadgeClass()'s note above about exactly that
     * mistake happening with booking status colors).
     */
    public function paymentStatusTone(): string
    {
        if ($this->payment_method === self::METHOD_PACKAGE) {
            return 'info';
        }

        return match ($this->payment_status) {
            self::PAYMENT_PAID => 'ok',
            self::PAYMENT_PARTIAL => 'warn',
            default => 'neutral',
        };
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** The Custom Plan this booking was sold on, if any (null once the plan is deleted). */
    public function pricingProfile(): BelongsTo
    {
        return $this->belongsTo(RoomPricingProfile::class, 'room_pricing_profile_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RoomPlan::class, 'room_plan_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** The redemption history row for this booking's coupon, if it was ever completed with one. */
    public function couponUsage(): HasOne
    {
        return $this->hasOne(CouponUsage::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function hotspotUser(): BelongsTo
    {
        return $this->belongsTo(HotspotUser::class, 'hotspot_user_id');
    }

    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    /**
     * Set only when this booking was auto-created by SharedSession::close()
     * (the inverse of SharedSession::booking()). Used by the Financials
     * module to detect a session-derived booking via the FK rather than by
     * matching the free-text 'Auto-created from shared session.' notes.
     */
    public function sharedSession(): HasOne
    {
        return $this->hasOne(SharedSession::class);
    }

    /** Net (post-coupon) room charge plus any attached product sales (already net of their own discount). */
    public function grandTotal(): float
    {
        return $this->netRoomCharge() + (float) ($this->sale?->total ?? 0);
    }

    /**
     * Whether this booking's Sale (room extras / running tab) can still be
     * changed via BookingController::addItem()/removeItem()/updateItemQuantity().
     * Requires $this->room to already be loaded (every call site does).
     *
     * Exclusive rooms: open for the whole active lifecycle (pending ->
     * confirmed -> checked_in); locked once the booking reaches a terminal
     * state (completed/cancelled/no_show) — those are finalized, paid-or-
     * closed invoices and must not be edited after the fact.
     *
     * Shared rooms: a reservation only gets a running tab once it's live
     * (checked_in) — never while still pending/confirmed, because
     * SalesService::saleForBooking() creates its Sale as 'completed'
     * immediately, which would be wrong for a reservation nobody has
     * arrived for yet and would risk a second, orphaned Sale when the
     * session's own tab is transferred at close (Sale has no unique
     * constraint on booking_id). And not once 'completed' either: a
     * completed shared-room booking is one whose session has already
     * closed via SharedSessionController::close() and been fully paid —
     * exactly the finalized invoice this guard exists to protect, so
     * unlike an exclusive room, 'completed' is excluded here, not included.
     */
    public function invoiceIsEditable(): bool
    {
        if ($this->room->isShared()) {
            return $this->status === 'checked_in';
        }

        return in_array($this->status, ['pending', 'confirmed', 'checked_in', 'open'], true);
    }

    /** An exclusive-room booking that started now with no end time yet — see Booking's open-session docs. */
    public function isOpenSession(): bool
    {
        return $this->status === 'open';
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'confirmed' => 'blue',
            'checked_in' => 'teal',
            'open' => 'cyan',
            'completed' => 'green',
            'cancelled' => 'red',
            'no_show' => 'orange',
            default => 'gray',
        };
    }

    /**
     * Tailwind badge classes for statusColor(), as one string — the single
     * source of truth for status-pill styling. Several views used to keep
     * their own local color=>class lookup array instead of calling this,
     * and every one of them was missing 'teal'/'orange' (checked_in/no_show
     * silently rendered as the gray fallback instead of their real color) —
     * a real bug, not a hypothetical one. New/updated views should call
     * this directly rather than re-deriving a mapping from statusColor().
     */
    public function statusBadgeClass(): string
    {
        return match ($this->statusColor()) {
            'yellow' => 'bg-yellow-100 text-yellow-800',
            'blue' => 'bg-blue-100 text-blue-800',
            'teal' => 'bg-teal-100 text-teal-800',
            'cyan' => 'bg-cyan-100 text-cyan-800',
            'green' => 'bg-green-100 text-green-800',
            'red' => 'bg-red-100 text-red-800',
            'orange' => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'checked_in' => 'Checked In',
            'no_show' => 'No Show',
            'open' => __('app.booking.duration_type.badge'),
            default => ucfirst($this->status),
        };
    }

    public function timeRange(): string
    {
        if ($this->end_time === null) {
            // Open Session: no end yet — isolated the same way as the normal
            // range below, for the same RTL-digit-reordering reason.
            return "\u{2066}".Carbon::parse($this->start_time)->format('h:i A')."\u{2069}";
        }

        $range = Carbon::parse($this->start_time)->format('h:i A')
            .' - '
            .Carbon::parse($this->end_time)->format('h:i A');

        // Unicode bidi isolate (U+2066/U+2069): this is always LTR content
        // (digits + AM/PM), but plain ASCII digits/hyphens are direction-
        // neutral to the bidi algorithm, so embedded in an RTL page
        // (Arabic locale) it silently reorders — e.g. "07:00 PM - 08:00 PM"
        // renders as "PM - 08:00 PM 07:00". Isolating it here fixes every
        // call site at once rather than requiring dir="ltr" wrapping in
        // each of the five views that call this.
        return "\u{2066}{$range}\u{2069}";
    }

    /** The reservation's planned start as one instant, for grace-period math. */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->booking_date->format('Y-m-d').' '.$this->start_time);
    }

    /**
     * The reservation's planned end as one instant, for the auto-completion
     * sweep. Rolls to the next day if end_time <= start_time — current
     * validation always keeps start/end on the same calendar day, so this
     * branch shouldn't be reachable today, but it's cheap insurance against
     * ever silently computing a negative/zero duration if that constraint
     * loosens later.
     */
    public function endsAt(): Carbon
    {
        $start = $this->startsAt();
        $end = Carbon::parse($this->booking_date->format('Y-m-d').' '.$this->end_time);

        return $end->lte($start) ? $end->addDay() : $end;
    }

    /**
     * Pure time math: has this booking's no-show grace period elapsed?
     * Says nothing about status or room type — callers (the check-in flow,
     * availability queries, the no-show sweep) combine this with
     * status === 'confirmed' and room->isShared() as needed, so this stays
     * the one place the cutoff itself is computed.
     */
    public function isPastNoShowGrace(): bool
    {
        return $this->startsAt()->addMinutes(self::NO_SHOW_GRACE_MINUTES)->isPast();
    }

    /**
     * Seats reserved but never claimed, once checked in — null beforehand.
     * party_size stays the original reservation size (never overwritten at
     * check-in) specifically so this comparison remains possible later.
     */
    public function noShowSeats(): ?int
    {
        if ($this->checked_in_party_size === null) {
            return null;
        }

        return max(0, $this->party_size - $this->checked_in_party_size);
    }
}
