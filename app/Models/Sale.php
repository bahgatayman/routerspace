<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'booking_id',
        'shared_session_id',
        'hotspot_user_id',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'sold_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }

    /** Realized product sales (open session tabs are not yet sold). Money rule shared by every report. */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function sharedSession(): BelongsTo
    {
        return $this->belongsTo(SharedSession::class);
    }

    public function hotspotUser(): BelongsTo
    {
        return $this->belongsTo(HotspotUser::class, 'hotspot_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'open' => 'yellow',
            'completed' => 'green',
            'cancelled' => 'red',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}
