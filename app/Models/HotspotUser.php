<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotspotUser extends Model
{
    protected $fillable = [
        'owner_id',
        'name',
        'phone',
        'phone_normalized',
        'router_username',
        'password',
        'speed_download',
        'speed_upload',
        'status',
        'router_sync_status',
        'router_synced_at',
        'router_sync_error',
        'speed_profile_id',
        'email',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'router_synced_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'password',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function speedProfile(): BelongsTo
    {
        return $this->belongsTo(SpeedProfile::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'hotspot_user_id');
    }

    public function sharedSessions(): HasMany
    {
        return $this->hasMany(SharedSession::class, 'hotspot_user_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'hotspot_user_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(MemberPackage::class, 'hotspot_user_id');
    }

    public function hasOpenSharedSession(): bool
    {
        return $this->sharedSessions()->where('status', 'open')->exists();
    }
}
