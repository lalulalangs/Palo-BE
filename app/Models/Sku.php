<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Sku extends Model
{
    use HasFactory;

    protected $fillable = [
        'variant_id',
        'sku_code',
        'price_override',
        'stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_override' => 'decimal:2',
            'stock' => 'integer',
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
}
