<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Antrean pesanan. Tidak ada tombol "Buat" dan tidak ada aksi hapus.
 * Otorisasi `create` dan `delete` sudah ditolak oleh `OrderPolicy`.
 */
class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
