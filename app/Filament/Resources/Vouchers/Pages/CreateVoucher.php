<?php

namespace App\Filament\Resources\Vouchers\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\Vouchers\VoucherResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVoucher extends CreateRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = VoucherResource::class;
}
