<?php

namespace App\Models;

use App\Observers\SkuObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

#[ObservedBy([SkuObserver::class])]
class Sku extends Model
{
    use HasFactory;

    protected $fillable = [
        'variant_id',
        'sku_code',
        'price_override',
        'stock',
        'weight_grams',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_override' => 'decimal:2',
            'stock' => 'integer',
            'weight_grams' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function product(): HasOneThrough
    {
        return $this->hasOneThrough(
            Product::class,
            ProductVariant::class,
            'id',          // Foreign key on ProductVariant table...
            'id',          // Foreign key on Product table...
            'variant_id',  // Local key on Sku table...
            'product_id'   // Local key on ProductVariant table...
        );
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Get effective unit price (respects price_override or falls back to product base_price).
     */
    public function getEffectivePriceAttribute(): float
    {
        if ($this->price_override !== null) {
            return (float) $this->price_override;
        }

        return (float) ($this->product?->base_price ?? 0);
    }

    /**
     * Get currently reserved stock from pending orders with active soft locks.
     */
    public function getReservedStockAttribute(): int
    {
        return (int) OrderItem::query()
            ->where('sku_id', $this->id)
            ->whereHas('order', function ($query): void {
                $query->where('status', Order::STATUS_PENDING)
                    ->where('stock_locked_until', '>', now());
            })
            ->sum('quantity');
    }

    /**
     * Get real-time available stock (physical stock minus active soft locks).
     */
    public function getAvailableStockAttribute(): int
    {
        return max(0, $this->stock - $this->reserved_stock);
    }
}
