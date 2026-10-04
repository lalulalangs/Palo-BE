<?php

namespace App\Filament\Resources\Skus\Pages;

use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Skus\SkuResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Halaman buat SKU.
 *
 * `on_hand` boleh diisi di sini HANYA saat pembuatan. Setelah itu, perubahan
 * stok harus lewat aksi "Sesuaikan Stok" supaya ada baris
 * `inventory_adjustments` (PRD §6A).
 *
 * Karena kolom `on_hand` punya default 0 di database, mengisi 0 saat membuat
 * tetap menghasilkan jejak: adjustment `initial_count` sebesar 0 dicatat
 * sebagai bukti bahwa stok awal benar-benar nol, bukan sekadar lupa diisi.
 */
class CreateSku extends CreateRecord
{
    protected static string $resource = SkuResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $record = parent::handleRecordCreation($data);

            $initialStock = (int) ($record->on_hand ?? 0);

            // Jejak audit untuk stok awal. `stock_before` 0 -> `stock_after`
            // sama dengan nilai yang diinput admin.
            InventoryAdjustment::create([
                'sku_id' => $record->getKey(),
                'quantity_delta' => $initialStock,
                'stock_before' => 0,
                'stock_after' => $initialStock,
                'reason' => 'initial_count',
                'note' => 'Stok awal saat SKU dibuat.',
                'created_by' => auth()->id(),
            ]);

            app(LogAdminActivity::class)->created($record, $record->getAttributes());

            return $record;
        });
    }
}
