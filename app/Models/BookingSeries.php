<?php

namespace App\Models;

use App\Support\Recurrence\RecurrenceRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring booking: the repeat rule plus what every occurrence books.
 * The occurrences are ordinary Booking rows (booking_series_id), each with its
 * own status, payment and price — see RecurringBookingService.
 */
class BookingSeries extends Model
{
    public const ACTIVE = 'active';

    public const STOPPED = 'stopped';

    /** The customer owes the whole series (one series-level balance, paid onto its dates). */
    public const PLAN_UPFRONT = 'upfront';

    /** Each date is paid on its own. */
    public const PLAN_PER_SESSION = 'per_session';

    public const PLANS = [self::PLAN_PER_SESSION, self::PLAN_UPFRONT];

    protected $table = 'booking_series';

    protected $fillable = [
        'owner_id', 'room_id', 'hotspot_user_id', 'room_pricing_profile_id',
        'frequency', 'weekdays', 'starts_on', 'ends_on', 'occurrence_limit',
        'start_time', 'end_time', 'party_size', 'guest_count', 'notes',
        'price_per_hour', 'occurrence_price', 'pricing_note', 'payment_plan', 'member_package_id',
        'status', 'stopped_at', 'generated_through', 'unresolved', 'request_token',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'unresolved' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'generated_through' => 'date',
            'stopped_at' => 'datetime',
            'occurrence_limit' => 'integer',
            'party_size' => 'integer',
            'guest_count' => 'integer',
            'price_per_hour' => 'decimal:2',
            'occurrence_price' => 'decimal:2',
        ];
    }

    public function memberPackage(): BelongsTo
    {
        return $this->belongsTo(MemberPackage::class);
    }

    public function isUpfront(): bool
    {
        return $this->payment_plan === self::PLAN_UPFRONT;
    }

    /** Minutes one date of the series lasts. */
    public function lengthMinutes(): int
    {
        $toMin = fn ($t) => (int) substr((string) $t, 0, 2) * 60 + (int) substr((string) $t, 3, 2);

        return $toMin($this->end_time) - $toMin($this->start_time);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function hotspotUser(): BelongsTo
    {
        return $this->belongsTo(HotspotUser::class);
    }

    public function pricingProfile(): BelongsTo
    {
        return $this->belongsTo(RoomPricingProfile::class, 'room_pricing_profile_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'booking_series_id')->orderBy('booking_date')->orderBy('start_time');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function rule(): RecurrenceRule
    {
        return RecurrenceRule::make(
            $this->frequency,
            $this->weekdays,
            $this->starts_on->toDateString(),
            $this->ends_on?->toDateString(),
            $this->occurrence_limit,
        );
    }

    /** "Every day" / "Every Mon, Wed" — localized. */
    public function frequencyLabel(): string
    {
        if ($this->frequency === RecurrenceRule::DAILY) {
            return __('app.series.every_day');
        }

        $names = collect($this->weekdays ?? [])->sort()
            ->map(fn ($d) => __('app.series.weekdays_short.'.$d))->implode(__('app.series.list_separator'));

        return __('app.series.every_weekdays', ['days' => $names]);
    }

    /** "Until 30 Nov" / "10 dates". */
    public function endLabel(): string
    {
        return $this->occurrence_limit
            ? trans_choice('app.series.ends_after', $this->occurrence_limit, ['count' => $this->occurrence_limit])
            : __('app.series.ends_on', ['date' => $this->ends_on?->translatedFormat('j M Y')]);
    }

    public function timeRange(): string
    {
        return substr((string) $this->start_time, 0, 5).' – '.substr((string) $this->end_time, 0, 5);
    }
}
