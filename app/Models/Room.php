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
use Illuminate\Support\Str;

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

    /**
     * public_id: stable, random UUID used by the customer API instead of the
     * internal primary key. Generated on create, never mass-assignable, and
     * immutable once set. It identifies a room — it authorizes nothing.
     */
    protected static function booted(): void
    {
        static::creating(function (Room $room) {
            if (blank($room->public_id)) {
                $room->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (Room $room) {
            if ($room->isDirty('public_id') && filled($room->getOriginal('public_id'))) {
                $room->public_id = $room->getOriginal('public_id');
            }
        });
    }

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

    /** True when the price customers would see (RoomPricingService::displayPrice) is above zero. */
    public function hasDisplayablePrice(): bool
    {
        return app(RoomPricingService::class)->displayPrice($this) > 0;
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

    /*
    |--------------------------------------------------------------------------
    | Multiple uses of one physical room
    |--------------------------------------------------------------------------
    | A physical room (physical_room_id IS NULL — every room created the
    | normal way) can be offered under other independently configured uses
    | ("Training Room", "Studio"…). Each use is its own room row; all of them
    | share ONE availability: a booking of any use occupies the physical room
    | for the other uses (AvailabilityService), and booking writes lock the
    | physical room's row (lockInventory()). physical_room_id is deliberately
    | not mass-assignable: only RoomController::storeUse() sets it.
    */

    /** The physical room this use belongs to (null for a physical room). */
    public function physicalRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'physical_room_id');
    }

    /** The other uses offered on this physical room (only meaningful on a physical room). */
    public function uses(): HasMany
    {
        return $this->hasMany(Room::class, 'physical_room_id')->orderBy('name')->orderBy('id');
    }

    public function isAdditionalUse(): bool
    {
        return $this->physical_room_id !== null;
    }

    /** Id of the room row that stands for the physical space (itself for a physical room). */
    public function inventoryRootId(): int
    {
        return (int) ($this->physical_room_id ?? $this->id);
    }

    /** @var array<int, int>|null */
    private ?array $inventoryIdsCache = null;

    /**
     * Every room id sharing this room's availability: the physical room and
     * all its uses (active or not — a deactivated use's bookings still hold
     * the room). Just [id] for a room with no other uses.
     *
     * @return array<int, int>
     */
    public function inventoryRoomIds(): array
    {
        if ($this->inventoryIdsCache === null) {
            $rootId = $this->inventoryRootId();
            $this->inventoryIdsCache = array_values(array_unique(array_merge(
                [$rootId],
                static::where('physical_room_id', $rootId)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            )));
        }

        return $this->inventoryIdsCache;
    }

    /**
     * Fill inventoryRoomIds() for a whole list of rooms with one query (the
     * rooms grid, calendars), instead of one query per room.
     *
     * @param  iterable<int, Room>  $rooms
     */
    public static function primeInventory(iterable $rooms): void
    {
        $rooms = collect($rooms);
        $rootIds = $rooms->map(fn (Room $r) => $r->inventoryRootId())->unique()->values();
        if ($rootIds->isEmpty()) {
            return;
        }

        $byRoot = static::whereIn('physical_room_id', $rootIds)->get(['id', 'physical_room_id'])
            ->groupBy('physical_room_id')
            ->map(fn ($uses) => $uses->pluck('id')->map(fn ($id) => (int) $id)->all());

        foreach ($rooms as $room) {
            $rootId = $room->inventoryRootId();
            $room->inventoryIdsCache = array_values(array_unique(array_merge([$rootId], $byRoot[$rootId] ?? [])));
        }
    }

    /** The ids of the OTHER uses sharing this room's availability (empty for most rooms). */
    public function otherUseIds(): array
    {
        return array_values(array_diff($this->inventoryRoomIds(), [(int) $this->id]));
    }

    /**
     * For booking writes, at the START of the DB::transaction(): lock this
     * use's row, then its physical room's row — every use of one physical
     * room queues on that one row, so two uses can't be booked for
     * overlapping times concurrently. Returns this use's (locked) row.
     *
     * Only LOCKING reads before the locks are held: on InnoDB a transaction's
     * REPEATABLE READ snapshot is taken at its first non-locking read, and a
     * snapshot taken before waiting would hide the booking another request
     * committed meanwhile (measured: both overlapping bookings succeeded when
     * the physical room id was read with a plain SELECT first). Callers must
     * likewise not run plain SELECTs in the transaction before calling this.
     *
     * Lock order is always use → physical room, and nothing locks a use while
     * holding a physical room, so no two requests wait on each other in a
     * cycle. Genuine row locks on MySQL; a no-op on SQLite, where the callers'
     * post-write re-check still applies.
     */
    public static function lockInventory(int $roomId): self
    {
        $use = static::whereKey($roomId)->lockForUpdate()->firstOrFail();
        if ($use->physical_room_id !== null) {
            static::whereKey($use->physical_room_id)->lockForUpdate()->firstOrFail();
        }

        return $use;
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
        // Any use of the same physical room occupies it (inventoryRoomIds()).
        return Booking::whereIn('room_id', $this->inventoryRoomIds())
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

    /** Gallery, in display order. */
    public function images(): HasMany
    {
        return $this->hasMany(RoomImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The cover photo if one's set, else the first uploaded image (by sort_order) — null if the room has none. */
    public function coverImage(): ?RoomImage
    {
        $images = $this->relationLoaded('images') ? $this->images : $this->images()->get();

        return $images->firstWhere('is_cover', true) ?? $images->first();
    }

    public function hasImages(): bool
    {
        return $this->relationLoaded('images') ? $this->images->isNotEmpty() : $this->images()->exists();
    }

    /** The cover photo's URL, or null when the room has no images (caller shows a placeholder). */
    public function coverImageUrl(): ?string
    {
        return $this->coverImage()?->thumbnailUrl();
    }
}
