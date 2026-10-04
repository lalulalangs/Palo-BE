<?php

namespace App\Filament\Resources\Vouchers\Pages;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Vouchers\VoucherResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman buat voucher.
 */
class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    /**
     * `used_count` SELALU 0 untuk voucher baru.
     *
     * Field-nya sudah `dehydrated(false)`, jadi nilainya tidak pernah masuk
     * dari form. Pengaman ini diulang di sini karena `used_count` adalah
     * counter atomik: kalau bisa diisi bebas, kuota yang sudah terpakai bisa
     * dihapus dan AC §3.10 kehilangan makna.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['used_count']);

        $data['used_count'] = 0;

        return $data;
    }

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
