<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Filament\Resources\StockOpnames\StockOpnameResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateStockOpname extends CreateRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Draft sesi opname berhasil dibuat';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Simpan sebagai Draft');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Kembali ke Daftar');
    }
}
