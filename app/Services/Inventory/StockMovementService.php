<?php

namespace App\Services\Inventory;

use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\StockOpname;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class StockMovementService
{
    /**
     * Catat mutasi stok atomik dengan row-level lock untuk integritas data.
     */
    public function recordMovement(
        Sku $sku,
        int $quantityChange,
        string $type,
        ?int $userId = null,
        ?Model $reference = null,
        ?string $notes = null
    ): StockMovement {
        return DB::transaction(function () use ($sku, $quantityChange, $type, $userId, $reference, $notes) {
            if ($quantityChange === 0) {
                throw new InvalidArgumentException('Perubahan kuantitas stok tidak boleh bernilai nol (0).');
            }

            /** @var Sku $lockedSku */
            $lockedSku = Sku::query()
                ->whereKey($sku->id)
                ->lockForUpdate()
                ->firstOrFail();

            $stockBefore = (int) $lockedSku->stock;
            $stockAfter = $stockBefore + $quantityChange;

            if ($stockAfter < 0) {
                throw new InvalidArgumentException(
                    "Stok SKU {$lockedSku->sku_code} tidak mencukupi untuk pengurangan ini (Stok saat ini: {$stockBefore}, Perubahan: {$quantityChange})."
                );
            }

            $lockedSku->update(['stock' => $stockAfter]);

            return StockMovement::create([
                'sku_id' => $lockedSku->id,
                'user_id' => $userId,
                'type' => $type,
                'quantity_change' => $quantityChange,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference_type' => $reference ? $reference->getMorphClass() : null,
                'reference_id' => $reference ? $reference->getKey() : null,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Terapkan hasil opname fisik ke saldo SKU dan buat mutasi penyesuaian (OPNAME_ADJUSTMENT).
     */
    public function applyOpname(StockOpname $opname, ?int $userId = null): void
    {
        DB::transaction(function () use ($opname, $userId) {
            /** @var StockOpname $lockedOpname */
            $lockedOpname = StockOpname::query()
                ->whereKey($opname->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOpname->status !== StockOpname::STATUS_DRAFT) {
                throw new LogicException("Sesi opname {$lockedOpname->opname_number} tidak dalam status draft dan tidak dapat diterapkan.");
            }

            $lockedOpname->loadMissing('items');

            if ($lockedOpname->items->isEmpty()) {
                throw new LogicException("Sesi opname {$lockedOpname->opname_number} tidak memiliki item untuk disesuaikan.");
            }

            foreach ($lockedOpname->items as $item) {
                /** @var Sku $lockedSku */
                $lockedSku = Sku::query()
                    ->whereKey($item->sku_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $currentStock = (int) $lockedSku->stock;
                $physicalStock = (int) $item->physical_stock;

                if ($physicalStock < 0) {
                    throw new InvalidArgumentException("Stok fisik SKU {$lockedSku->sku_code} tidak boleh bernilai negatif (ditemukan: {$physicalStock}).");
                }

                $difference = $physicalStock - $currentStock;

                $item->update([
                    'system_stock' => $currentStock,
                    'difference' => $difference,
                ]);

                if ($difference !== 0) {
                    $note = "Penyesuaian opname fisik {$lockedOpname->opname_number} (Fisik: {$physicalStock}, Sistem: {$currentStock})";
                    if (filled($item->notes)) {
                        $note .= " — {$item->notes}";
                    }

                    $this->recordMovement(
                        sku: $lockedSku,
                        quantityChange: $difference,
                        type: StockMovement::TYPE_OPNAME_ADJUSTMENT,
                        userId: $userId ?? $lockedOpname->user_id,
                        reference: $lockedOpname,
                        notes: $note
                    );
                }
            }

            $lockedOpname->update([
                'status' => StockOpname::STATUS_COMPLETED,
                'completed_at' => now(),
                'user_id' => $userId ?? $lockedOpname->user_id,
            ]);
        });
    }
}
