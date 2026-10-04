<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman buat akun admin. Hanya Superadmin yang bisa mencapainya.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        // `password` tidak ikut masuk log: `LogAdminActivity::scrub()`
        // membuang kunci yang mengandung "password" (PRD §3.12).
        UserResource::logCreation($record);

        return $record;
    }
}
