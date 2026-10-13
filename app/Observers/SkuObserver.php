<?php

namespace App\Observers;

use App\Models\Sku;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementService;

class SkuObserver
{
    /**
     * Catat mutasi initial_stock saat SKU baru dibuat dengan stok awal > 0.
     */
    public function created(Sku $sku): void
    {
        if (StockMovementService::$isRecordingMovement) {
            return;
        }

        $initialStock = (int) $sku->stock;

        if ($initialStock <= 0) {
            return;
        }

        StockMovement::create([
            'sku_id' => $sku->id,
            'user_id' => auth('admin')->id() ?? auth()->id(),
            'type' => StockMovement::TYPE_INITIAL_STOCK,
            'quantity_change' => $initialStock,
            'stock_before' => 0,
            'stock_after' => $initialStock,
            'reference_type' => null,
            'reference_id' => null,
            'notes' => 'Pencatatan stok awal saat pembuatan SKU / varian',
        ]);
    }

    /**
     * Catat mutasi manual_adjustment saat nilai stok SKU diedit langsung di admin panel.
     */
    public function updating(Sku $sku): void
    {
        if (StockMovementService::$isRecordingMovement) {
            return;
        }

        if (! $sku->isDirty('stock')) {
            return;
        }

        $stockBefore = (int) $sku->getOriginal('stock');
        $stockAfter = (int) $sku->stock;
        $difference = $stockAfter - $stockBefore;

        if ($difference === 0) {
            return;
        }

        StockMovement::create([
            'sku_id' => $sku->id,
            'user_id' => auth('admin')->id() ?? auth()->id(),
            'type' => StockMovement::TYPE_MANUAL_ADJUSTMENT,
            'quantity_change' => $difference,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'reference_type' => null,
            'reference_id' => null,
            'notes' => 'Penyesuaian stok langsung via admin panel (Edit Varian/Produk)',
        ]);
    }
}
