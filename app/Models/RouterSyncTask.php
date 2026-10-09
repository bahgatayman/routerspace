<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterSyncTask extends Model
{
    protected $fillable = [
        'owner_id',
        'hotspot_user_id',
        'speed_profile_id',
        'type',
        'status',
        'payload',
        'attempts',
        'last_error',
        'last_attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function hotspotUser(): BelongsTo
    {
        return $this->belongsTo(HotspotUser::class);
    }

    public function speedProfile(): BelongsTo
    {
        return $this->belongsTo(SpeedProfile::class);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', ['pending', 'failed']);
    }
}
