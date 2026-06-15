<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'room_id',
        'customer_id',
        'booking_date',
        'start_time',
        'end_time',
        'price_per_hour',
        'total_hours',
        'total_price',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_date'   => 'date',
            'price_per_hour' => 'decimal:2',
            'total_hours'    => 'decimal:2',
            'total_price'    => 'decimal:2',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending'   => 'yellow',
            'confirmed' => 'blue',
            'completed' => 'green',
            'cancelled' => 'red',
            default     => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }

    public function timeRange(): string
    {
        return Carbon::parse($this->start_time)->format('h:i A')
            . ' - '
            . Carbon::parse($this->end_time)->format('h:i A');
    }
}
