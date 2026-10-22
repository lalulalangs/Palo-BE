<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\StockOpname;
use App\Services\Inventory\StockMovementService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class EditStockOpname extends EditRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = StockOpnameResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perubahan draft opname berhasil disimpan';
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Simpan Perubahan Draft')
            ->visible(fn (): bool => ! $this->getRecord()->isCompleted());
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Kembali ke Daftar');
    }

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
                ->visible(fn (): bool => $this->getRecord()->isDraft())
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

            Action::make('cancel_opname')
                ->label('Batalkan Sesi Opname')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Batalkan Sesi Opname')
                ->modalDescription('Apakah Anda yakin ingin membatalkan sesi opname ini? Status dokumen akan diubah menjadi Dibatalkan dan saldo stok tidak akan dipengaruhi.')
                ->modalSubmitActionLabel('Ya, Batalkan Sesi')
                ->modalCancelActionLabel('Tidak')
                ->visible(fn (): bool => $this->getRecord()->isDraft())
                ->action(function (): void {
                    /** @var StockOpname $record */
                    $record = $this->getRecord();
                    $record->update(['status' => StockOpname::STATUS_CANCELLED]);

                    Notification::make()
                        ->title('Sesi opname berhasil dibatalkan')
                        ->warning()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),

            DeleteAction::make()
                ->label('Hapus Draft')
                ->modalHeading('Hapus Dokumen Opname')
                ->modalDescription('Apakah Anda yakin ingin menghapus dokumen opname ini? Seluruh data hasil input yang belum diterapkan akan dibersihkan secara permanen.')
                ->modalSubmitActionLabel('Ya, Hapus')
                ->modalCancelActionLabel('Tidak')
                ->visible(fn (): bool => ! $this->getRecord()->isCompleted()),
        ];
    }
}
