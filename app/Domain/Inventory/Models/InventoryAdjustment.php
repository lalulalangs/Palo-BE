<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Sku;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit perubahan stok manual.
 *
 * PRD §6A: "Stok SKU adalah sumber katalog tunggal; admin mencatat perubahan
 * stok dari penjualan offline agar ketersediaan web tidak keliru."
 * system_map §5 menyebut `inventory_adjustments` sebagai tambahan wajib.
 *
 * APPEND-ONLY: baris tidak pernah diubah atau dihapus. Dengan begitu
 * `skus.on_hand` selalu bisa direkonstruksi:
 *
 *     on_hand_sekarang = initial_count + SUM(quantity_delta)
 *
 * Ini penting karena `on_hand` diubah langsung, sementara tabel ini
 * menyediakan pembuktian. Tanpa audit, selisih stok tidak bisa ditelusuri.
 */
class InventoryAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku_id', 'quantity_delta', 'stock_before', 'stock_after',
        'reason', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Label Bahasa Indonesia untuk UI admin & dropdown Filament.
     */
    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'manual_adjustment' => 'Penyesuaian Manual',
            'offline_sale' => 'Penjualan Offline (Gerai)',
            'return_received' => 'Retur Pelanggan',
            'damaged' => 'Barang Rusak',
            'initial_count' => 'Opname Awal',
            default => $this->reason,
        };
    }
}
