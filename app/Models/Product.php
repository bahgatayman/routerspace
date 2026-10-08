<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'type',
        'price',
        'purchase_price',
        'sku',
        'track_stock',
        'stock_quantity',
        'low_stock_threshold',
        'stock_alert',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'low_stock_threshold' => 'integer',
            'track_stock' => 'boolean',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'coupon_products')->withTimestamps();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest('id');
    }

    public function isService(): bool
    {
        return $this->type === 'service';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'service' => 'Service',
            default => 'Product',
        };
    }

    public function typeColor(): string
    {
        return match ($this->type) {
            'service' => 'purple',
            default => 'blue',
        };
    }

    // ---- Inventory & cost (derived; only InventoryService changes stock) ----

    /**
     * Tracked, stocked products at or below their own threshold — the one
     * definition of "low stock" every caller shares (ProductController's
     * stock-filter chip, the admin low-stock alert count, and
     * ProductAnalyticsService's dashboard card). Out-of-stock (quantity<=0)
     * is deliberately a different bucket (see ProductController::scopeOut())
     * — "low" means still sellable but running down, not already empty.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('track_stock', true)->where('type', 'product')
            ->where('stock_quantity', '>', 0)
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    }

    /** Stock is counted only when the owner turned it on, and never for services. */
    public function tracksStock(): bool
    {
        return (bool) $this->track_stock && ! $this->isService();
    }

    /** in_stock | low | out for tracked products; 'untracked' otherwise. */
    public function stockStatus(): string
    {
        if (! $this->tracksStock()) {
            return 'untracked';
        }
        $stock = (int) $this->stock_quantity;
        if ($stock <= 0) {
            return 'out';
        }
        if ($this->low_stock_threshold !== null && $stock <= $this->low_stock_threshold) {
            return 'low';
        }

        return 'in_stock';
    }

    /** Selling price − purchase price. */
    public function profitPerUnit(): float
    {
        return round((float) $this->price - (float) $this->purchase_price, 2);
    }

    /** Margin (not markup): profit ÷ selling price × 100. Null when the price is 0. */
    public function marginPercent(): ?float
    {
        return (float) $this->price > 0 ? round($this->profitPerUnit() / (float) $this->price * 100, 1) : null;
    }

    /** What the stock on hand cost: stock × purchase price. */
    public function inventoryValue(): float
    {
        return round((int) $this->stock_quantity * (float) $this->purchase_price, 2);
    }

    /** What the stock on hand would sell for: stock × selling price. */
    public function potentialRevenue(): float
    {
        return round((int) $this->stock_quantity * (float) $this->price, 2);
    }

    /** Gross profit if all stock on hand sells: stock × (selling − purchase). */
    public function potentialProfit(): float
    {
        return round((int) $this->stock_quantity * $this->profitPerUnit(), 2);
    }
}
