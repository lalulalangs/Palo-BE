<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\StockOpname;
use App\Services\Inventory\StockMovementService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class EditStockOpname extends EditRecord
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply_opname')
                ->label('Terapkan Penyesuaian Stok')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Terapkan Penyesuaian Stok Opname')
                ->modalDescription('Apakah Anda yakin ingin menerapkan hasil opname ini? Saldo stok SKU akan diperbarui dan mutasi stok penyesuaian akan dicatat secara otomatis.')
                ->modalSubmitActionLabel('Ya, Terapkan')
                ->visible(fn (): bool => ! $this->getRecord()->isCompleted())
                ->action(function (StockMovementService $service): void {
                    /** @var StockOpname $record */
                    $record = $this->getRecord();

                    try {
                        $service->applyOpname($record, Auth::id() ? (int) Auth::id() : null);

                        Notification::make()
                            ->title('Penyesuaian stok opname berhasil diterapkan')
                            ->success()
                            ->send();

                        $this->refreshFormData(['status', 'completed_at']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal menerapkan opname')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
