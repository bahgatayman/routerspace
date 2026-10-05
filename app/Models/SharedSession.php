<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SharedSession extends Model
{
    protected $fillable = [
        'owner_id', 'room_id', 'hotspot_user_id', 'party_size',
        'session_date', 'start_time',
        'opened_at', 'closed_at', 'total_minutes', 'total_price',
        'status', 'booking_id', 'billing_unit', 'billed_price_per_hour', 'pricing_snapshot', 'plan_snapshot',
        'member_package_id', 'billing_buffer_minutes', 'room_pricing_profile_id', 'pricing_profile_name',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'session_date' => 'date',
        'total_minutes' => 'decimal:2',
        'total_price' => 'decimal:2',
        'billed_price_per_hour' => 'decimal:2',
        'pricing_snapshot' => 'array',
        'plan_snapshot' => 'array',
        'party_size' => 'integer',
        'billing_buffer_minutes' => 'integer',
    ];

    public function pricingProfile(): BelongsTo
    {
        return $this->belongsTo(RoomPricingProfile::class, 'room_pricing_profile_id');
    }

    public function memberPackage(): BelongsTo
    {
        return $this->belongsTo(MemberPackage::class);
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
        return $this->belongsTo(HotspotUser::class, 'hotspot_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The running "tab" of products while this session is open. */
    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    /** Whether this session's running tab can still be changed. */
    public function invoiceIsEditable(): bool
    {
        return $this->status === 'open';
    }
}
