<?php

namespace App\Filament\Resources\Vouchers\Pages;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Vouchers\VoucherResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman edit voucher.
 */
class EditVoucher extends EditRecord
{
    protected static string $resource = VoucherResource::class;

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
     * Lapis kedua untuk `used_count` (lapis pertama ada di
     * `VoucherResource::form()`). Menolak perubahan counter dari panel.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['used_count']);

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
