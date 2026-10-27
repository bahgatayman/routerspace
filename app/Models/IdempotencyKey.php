<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claimed request key (see App\Support\RequestIdempotency). Not to be
 * confused with App\Support\IdempotencyKey, the short-lived cache guard used
 * by the "add item" endpoints.
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    protected $fillable = ['owner_id', 'actor_type', 'actor_id', 'scope', 'key', 'request_hash', 'booking_id', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** `php artisan model:prune` (scheduled daily) removes expired keys. */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }
}
