<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Daftar akun admin.
 *
 * Resource ini sudah menolak akses di `UserResource::canAccess()` untuk role
 * selain Superadmin, jadi halaman ini hanya bisa dibuka Superadmin.
 */
class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
