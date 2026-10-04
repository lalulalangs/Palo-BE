<?php

namespace App\Filament\Resources\GuestCheckoutLogs\Pages;

use App\Filament\Resources\GuestCheckoutLogs\GuestCheckoutLogResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail niat WhatsApp. Tidak ada tombol edit atau hapus:
 * `GuestCheckoutLogPolicy` menolak keduanya dan log bersifat append-only.
 */
class ViewGuestCheckoutLog extends ViewRecord
{
    protected static string $resource = GuestCheckoutLogResource::class;
}
