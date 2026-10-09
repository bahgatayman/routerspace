<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Owner extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'business_name',
        'logo_path',
        'mikrotik_host',
        'mikrotik_port',
        'mikrotik_username',
        'mikrotik_password',
        'subscription_starts_at',
        'subscription_expires_at',
        'is_active',
        'plan_id',
    ];

    protected $hidden = [
        'password',
        'mikrotik_password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'mikrotik_port' => 'integer',
            'password' => 'hashed',
            'subscription_starts_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
            'is_active' => 'boolean',
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

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function sharedSessions(): HasMany
    {
        return $this->hasMany(SharedSession::class);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(StaffActivityLog::class);
    }

    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * In-app notifications for this owner. Overrides the Notifiable trait's
     * database-notification relation, which this app does not use.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function canAddMoreUsers(): bool
    {
        if (! $this->plan) {
            return false;
        }
        $currentCount = $this->hotspotUsers()->count();

        return $currentCount < $this->plan->max_members;
    }

    public function remainingUserSlots(): int
    {
        if (! $this->plan) {
            return 0;
        }
        $currentCount = $this->hotspotUsers()->count();

        return max(0, $this->plan->max_members - $currentCount);
    }

    public function usagePercentage(): float
    {
        if (! $this->plan || $this->plan->max_members == 0) {
            return 0;
        }

        return round(($this->hotspotUsers()->count() / $this->plan->max_members) * 100, 1);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'owner_features')
            ->withPivot('enabled_at')
            ->withTimestamps();
    }

    /**
     * Active feature keys, loaded once per model instance (i.e. once per request:
     * the auth guard keeps one Owner instance) instead of one query per check —
     * the feature middleware, sidebar and shared props ask 10-25 times a page.
     * enableFeature()/disableFeature() reset it; other requests get a fresh instance.
     *
     * @var array<int, string>|null
     */
    protected ?array $activeFeatureKeys = null;

    /** @return array<int, string> */
    public function activeFeatureKeys(): array
    {
        return $this->activeFeatureKeys ??= $this->features()->where('is_active', true)->pluck('key')->values()->all();
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->activeFeatureKeys(), true);
    }

    /**
     * Whether this owner has usable MikroTik router credentials. Router sync only
     * runs when the hotspot feature is enabled AND this returns true.
     */
    public function hasRouterConfigured(): bool
    {
        return filled($this->mikrotik_host) && filled($this->mikrotik_username);
    }

    /**
     * Public URL of the owner's uploaded brand image, or null when they have none
     * (callers fall back to initials()).
     *
     * Built with asset() rather than Storage::url(): the latter hard-codes
     * APP_URL, so an image uploaded while running on `artisan serve` (:8000)
     * came back pointing at http://localhost (:80) and rendered broken.
     * asset() follows whatever host the request actually came in on.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? asset('storage/'.$this->logo_path)
            : null;
    }

    /**
     * Up to two letters from the business name — the placeholder shown wherever
     * the brand image would go.
     */
    public function initials(): string
    {
        $source = filled($this->business_name) ? $this->business_name : (string) $this->name;

        $letters = collect(preg_split('/\s+/', trim($source)))
            ->filter()
            ->take(2)
            ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        return $letters !== '' ? $letters : '?';
    }

    public function enableFeature(string $key): void
    {
        $feature = Feature::where('key', $key)->firstOrFail();
        $this->features()->syncWithoutDetaching([
            $feature->id => ['enabled_at' => now()],
        ]);
        $this->activeFeatureKeys = null;
    }

    /** Enable this owner's plan default features. Safe to call repeatedly. */
    public function applyPlanFeatures(): void
    {
        foreach ($this->plan?->defaultFeatures() ?? [] as $key) {
            if (Feature::where('key', $key)->where('is_active', true)->exists()) {
                $this->enableFeature($key);
            }
        }
    }

    // ---- Plan limits (0 / null on the plan = unlimited) ----

    public function canAddMoreWorkspaces(): bool
    {
        $limit = $this->plan?->max_workspaces;
        if (! $limit || $limit <= 0) {
            return true;
        }

        return $this->workspaces()->count() < $limit;
    }

    public function remainingWorkspaceSlots(): ?int
    {
        $limit = $this->plan?->max_workspaces;
        if (! $limit || $limit <= 0) {
            return null; // unlimited
        }

        return max(0, $limit - $this->workspaces()->count());
    }

    public function canAddMoreRooms(): bool
    {
        $limit = $this->plan?->max_rooms;
        if (! $limit || $limit <= 0) {
            return true;
        }

        return $this->rooms()->count() < $limit;
    }

    public function remainingRoomSlots(): ?int
    {
        $limit = $this->plan?->max_rooms;
        if (! $limit || $limit <= 0) {
            return null; // unlimited
        }

        return max(0, $limit - $this->rooms()->count());
    }

    public function canAddMoreProducts(): bool
    {
        $limit = $this->plan?->max_products;
        if (! $limit || $limit <= 0) {
            return true;
        }

        return $this->products()->count() < $limit;
    }

    public function remainingProductSlots(): ?int
    {
        $limit = $this->plan?->max_products;
        if (! $limit || $limit <= 0) {
            return null; // unlimited
        }

        return max(0, $limit - $this->products()->count());
    }

    public function disableFeature(string $key): void
    {
        $feature = Feature::where('key', $key)->first();
        if ($feature) {
            $this->features()->detach($feature->id);
        }
        $this->activeFeatureKeys = null;
    }

    public function isSubscriptionActive(): bool
    {
        if (! $this->subscription_expires_at) {
            return false;
        }

        return $this->is_active && $this->subscription_expires_at->isFuture();
    }

    public function daysUntilExpiry(): int
    {
        if (! $this->subscription_expires_at) {
            return 0;
        }

        return (int) max(0, now()->diffInDays($this->subscription_expires_at, false));
    }

    public function subscriptionStatus(): string
    {
        if (! $this->subscription_expires_at) {
            return 'never';
        }
        if (! $this->is_active) {
            return 'disabled';
        }
        if ($this->subscription_expires_at->isPast()) {
            return 'expired';
        }
        if ($this->daysUntilExpiry() <= 7) {
            return 'expiring_soon';
        }

        return 'active';
    }
}
