<?php

namespace App\Filament\Resources\Products\Pages;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman edit produk. Di halaman ini juga tampil tiga RelationManager
 * (Varian, SKU, Media) sehingga admin bisa mengisi katalog tanpa berpindah
 *resource.
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // `DeleteAction` sudah membaca `ProductPolicy::delete()` sendiri
            // lewat `getDefaultActionAuthorizationResponse()`, jadi tidak perlu
            // `->visible()` manual. Menyembunyikan tombol tanpa policy yang
            // benar hanya memindahkan masalah, bukan menyelesaikannya.
            DeleteAction::make(),
        ];
    }

    /**
     * Catat perubahan ke `admin_activity_logs` (PRD §3.10).
     *
     * Snapshot diambil SEBELUM `parent::handleRecordUpdate()`. Setelah
     * `save()`, Laravel menyinkronkan `original` dengan nilai baru sehingga
     * nilai lama tidak bisa diambil lagi.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = app(LogAdminActivity::class)->snapshot($record);

        $record = parent::handleRecordUpdate($record, $data);

        $diff = app(LogAdminActivity::class)->diff($before, $record->getAttributes());

        if ($diff !== []) {
            app(LogAdminActivity::class)->updated($record, $diff, $diff);
        }

        return $record;
    }
}
