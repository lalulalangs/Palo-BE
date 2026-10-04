<?php

namespace App\Filament\Resources\InventoryAdjustments\Pages;

use App\Filament\Resources\InventoryAdjustments\InventoryAdjustmentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Riwayat penyesuaian stok. Tidak punya halaman create, edit, maupun delete:
 * `InventoryAdjustmentPolicy` menolak semuanya dan tabelnya append-only.
 */
class ListInventoryAdjustments extends ListRecords
{
    protected static string $resource = InventoryAdjustmentResource::class;
}
