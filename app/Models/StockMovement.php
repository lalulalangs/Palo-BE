<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use HasFactory;

    public const TYPE_RESTOCK = 'restock';

    public const TYPE_SALE = 'sale';

    public const TYPE_CANCELLATION = 'cancellation';

    public const TYPE_OPNAME_ADJUSTMENT = 'opname_adjustment';

    public const TYPE_MANUAL_ADJUSTMENT = 'manual_adjustment';

    protected $fillable = [
        'sku_id',
        'user_id',
        'type',
        'quantity_change',
        'stock_before',
        'stock_after',
        'reference_type',
        'reference_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public static function getTypeLabels(): array
    {
        return [
            self::TYPE_RESTOCK => 'Restock / Masuk',
            self::TYPE_SALE => 'Penjualan',
            self::TYPE_CANCELLATION => 'Batal Pesanan',
            self::TYPE_OPNAME_ADJUSTMENT => 'Penyesuaian Opname',
            self::TYPE_MANUAL_ADJUSTMENT => 'Koreksi Manual',
        ];
    }
}
