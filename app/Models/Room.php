<?php

namespace App\Models;

use App\Services\RoomPricingService;
use App\Support\Pricing\PricingRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'owner_id',
        'name',
        'type',
        'capacity',
        'price_per_hour',
        'billing_unit',
        'billing_buffer_minutes',
        'pricing_model',
        'pricing_rules',
        'description',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'price_per_hour' => 'decimal:2',
            'billing_buffer_minutes' => 'integer',
            'pricing_rules' => 'array',
            'is_available' => 'boolean',
        ];
    }

    /** This room's pricing rules; rooms with no rules (every pre-existing room) are plain hourly. */
    public function pricingRules(): PricingRules
    {
        return PricingRules::fromStored($this->pricing_model, $this->pricing_rules);
    }

    /** "EGP 100.00/hr", "From EGP 50.00" — see RoomPricingService::summary(). */
    public function pricingSummary(): string
    {
        return app(RoomPricingService::class)->summary($this);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /** Custom Plans (fixed-price packages), in the owner's order. */
    public function plans(): HasMany
    {
        return $this->hasMany(RoomPlan::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Optional alternative hourly rates ("Photography — 700/hr"), active and inactive. */
    public function pricingProfiles(): HasMany
    {
        return $this->hasMany(RoomPricingProfile::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The profiles a new booking/session may choose. */
    public function activePricingProfiles(): HasMany
    {
        return $this->pricingProfiles()->where('is_active', true);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'coupon_rooms')->withTimestamps();
    }

    public function hasConflict(string $date, string $startTime, string $endTime, ?int $excludeBookingId = null): bool
    {
        // whereDate(), not where('booking_date', $date): the `date` cast
        // serializes to 'Y-m-d H:i:s' on write (Eloquent's date-format
        // config isn't cast-type-aware), so a bare 'Y-m-d' equality check
        // silently never matches on engines with no native DATE coercion
        // (SQLite stores the literal string; MySQL's real DATE column type
        // happens to truncate it back to a bare date on insert, which is
        // why this was invisible in a MySQL-backed environment).
        return $this->bookings()
            ->whereDate('booking_date', $date)
            ->where('status', '!=', 'cancelled')
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where(function ($q) use ($startTime, $endTime) {
                // An Open Session booking (end_time IS NULL) has no future
                // end to range-check — 'end_time > $startTime' would
                // silently evaluate to SQL NULL for that row and exclude it,
                // so it always counts as a conflict instead. See the
                // identical fix/comment on AvailabilityService::usedCapacity().
                $q->where(function ($q2) use ($startTime, $endTime) {
                    $q2->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                })->orWhere('status', 'open');
            })
            ->exists();
    }

    /** Bilingual label; unknown types fall back to the raw value. */
    public function typeLabel(): string
    {
        $key = 'app.room_type.'.$this->type;

        return Lang::has($key) ? __($key) : ucfirst((string) $this->type);
    }

    public function typeColor(): string
    {
        return match ($this->type) {
            'meeting' => 'blue',
            'training' => 'purple',
            'shared' => 'green',
            'office' => 'orange',
            'studio' => 'pink',
            default => 'gray',
        };
    }

    public function isShared(): bool
    {
        return $this->type === 'shared';
    }

    /**
     * 'available' | 'occupied' | 'unavailable'. Unlike Booking::statusColor()
     * (reads a stored column), Room has no stored status column — occupancy
     * is a live, cross-table read ($occupiedSeats) the caller must already
     * have batched: AvailabilityService::usedCapacityNowBulk() for exclusive
     * rooms, the withSum('sharedSessions') idiom for shared ones. This method
     * does no querying of its own, so a room list stays N+1-free.
     *
     * is_available=false always wins over a live "occupied" read — an owner
     * who flips a room off expects it to read Unavailable immediately, not
     * "Occupied" from a booking that's about to be superseded anyway.
     */
    public function statusKey(int $occupiedSeats = 0): string
    {
        if (! $this->is_available) {
            return 'unavailable';
        }

        return $occupiedSeats > 0 ? 'occupied' : 'available';
    }

    /** Bilingual status text; shared rooms read "6/10 occupied", exclusive rooms just "Occupied". */
    public function statusLabel(int $occupiedSeats = 0): string
    {
        return match ($this->statusKey($occupiedSeats)) {
            'unavailable' => __('app.workspace.unavailable'),
            'occupied' => $this->isShared()
                ? __('app.workspace.occupied_shared', ['used' => $occupiedSeats, 'total' => $this->effectiveCapacity()])
                : __('app.workspace.occupied'),
            default => __('app.workspace.available'),
        };
    }

    /** Bilingual label for this room's session-billing rule; unknown values fall back to the raw value. */
    public function billingUnitLabel(): string
    {
        $key = 'app.billing_unit.'.$this->billing_unit;

        return Lang::has($key) ? __($key) : ucfirst((string) $this->billing_unit);
    }

    /**
     * Capacity that's actually enforceable for booking-conflict purposes.
     * Non-shared room types are always exclusive (1), regardless of whatever
     * raw `capacity` value is stored — that column is decorative and
     * unvalidated per-type today (RoomController accepts 1-999 for any type),
     * so pinning the effective value here means a fat-fingered capacity can
     * never silently make an exclusive room double-bookable.
     */
    public function effectiveCapacity(): int
    {
        return $this->isShared() ? $this->capacity : 1;
    }

    public function sharedSessions(): HasMany
    {
        return $this->hasMany(SharedSession::class);
    }

    public function openSharedSessions(): HasMany
    {
        return $this->sharedSessions()->where('status', 'open');
    }

    /**
     * Seats free right now. Sums each open session's party_size rather than
     * counting rows — a party of 5 must consume 5 seats, not 1 — so a room
     * doesn't silently accept more people than it physically holds. Safe for
     * pre-party-size data: every legacy session has party_size=1, so the sum
     * equals the old row count for any data that predates this field.
     */
    public function availableSharedSlots(): int
    {
        $occupiedSeats = (int) $this->openSharedSessions()->sum('party_size');

        return max(0, $this->capacity - $occupiedSeats);
    }

    public function isSharedFull(): bool
    {
        return $this->availableSharedSlots() === 0;
    }
}
