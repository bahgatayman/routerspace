<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Owner extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'business_name',
        'mikrotik_host',
        'mikrotik_port',
        'mikrotik_username',
        'mikrotik_password',
        'subscription_starts_at',
        'subscription_expires_at',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'mikrotik_password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'mikrotik_port'          => 'integer',
            'password'               => 'hashed',
            'subscription_starts_at' => 'datetime',
            'subscription_expires_at'=> 'datetime',
            'is_active'              => 'boolean',
        ];
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function hotspotUsers(): HasMany
    {
        return $this->hasMany(HotspotUser::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'owner_features')
            ->withPivot('enabled_at')
            ->withTimestamps();
    }

    public function hasFeature(string $key): bool
    {
        return $this->features()->where('key', $key)->where('is_active', true)->exists();
    }

    public function enableFeature(string $key): void
    {
        $feature = Feature::where('key', $key)->firstOrFail();
        $this->features()->syncWithoutDetaching([
            $feature->id => ['enabled_at' => now()],
        ]);
    }

    public function disableFeature(string $key): void
    {
        $feature = Feature::where('key', $key)->first();
        if ($feature) {
            $this->features()->detach($feature->id);
        }
    }

    public function isSubscriptionActive(): bool
    {
        if (!$this->subscription_expires_at) return false;
        return $this->is_active && $this->subscription_expires_at->isFuture();
    }

    public function daysUntilExpiry(): int
    {
        if (!$this->subscription_expires_at) return 0;
        return max(0, now()->diffInDays($this->subscription_expires_at, false));
    }

    public function subscriptionStatus(): string
    {
        if (!$this->subscription_expires_at) return 'never';
        if (!$this->is_active) return 'disabled';
        if ($this->subscription_expires_at->isPast()) return 'expired';
        if ($this->daysUntilExpiry() <= 7) return 'expiring_soon';
        return 'active';
    }
}
