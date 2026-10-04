<?php

namespace App\Filament\Resources\Skus\Pages;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Skus\SkuResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman edit SKU.
 *
 * Pengaman lapis kedua untuk `on_hand`: form-nya sudah mengunci field itu
 * (`SkuResource::form()`), dan di sini payload-nya juga dibersihkan. Dua lapis
 * karena aturan "jangan pernah mengedit `skus.on_hand` langsung" terlalu penting
 * untuk bergantung pada satu hal saja.
 */
class EditSku extends EditRecord
{
    protected static string $resource = SkuResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Lapis kedua. Lapis pertama ada di `SkuResource::form()`
        // (`->dehydrated(fn ($operation) => $operation === 'create')`).
        unset($data['on_hand']);

        return $data;
    }

    /**
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
