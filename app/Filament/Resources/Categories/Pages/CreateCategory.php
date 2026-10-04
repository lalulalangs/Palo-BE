<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman buat kategori/koleksi. Setiap perubahan dicatat di
 * `admin_activity_logs` (PRD §3.10).
 */
class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        app(LogAdminActivity::class)->created($record, $record->getAttributes());

        return $record;
    }
}
