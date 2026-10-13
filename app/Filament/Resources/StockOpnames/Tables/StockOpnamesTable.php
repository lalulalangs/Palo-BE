<?php

namespace App\Filament\Resources\StockOpnames\Tables;

use App\Models\StockOpname;
use App\Services\Inventory\StockMovementService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class StockOpnamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('opname_number')
                    ->label('No. Opname')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('created_at')
                    ->label('Tanggal Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('auditor.name')
                    ->label('Petugas Pemeriksa')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Total SKU')
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        StockOpname::STATUS_COMPLETED => 'Selesai',
                        StockOpname::STATUS_CANCELLED => 'Dibatalkan',
                        default => 'Draft',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        StockOpname::STATUS_COMPLETED => 'success',
                        StockOpname::STATUS_CANCELLED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('completed_at')
                    ->label('Waktu Selesai')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                Action::make('apply_opname')
                    ->label('Terapkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Terapkan Penyesuaian Stok Opname')
                    ->modalDescription('Apakah Anda yakin ingin menerapkan hasil opname fisik ini? Saldo stok SKU akan disesuaikan dan log mutasi stok akan dicatat secara otomatis ke sistem.')
                    ->modalSubmitActionLabel('Ya, Terapkan Penyesuaian')
                    ->modalCancelActionLabel('Tidak')
                    ->visible(fn (StockOpname $record): bool => $record->isDraft())
                    ->action(function (StockOpname $record, StockMovementService $service): void {
                        try {
                            $service->applyOpname($record, Auth::id() ? (int) Auth::id() : null);

                            Notification::make()
                                ->title('Penyesuaian stok opname berhasil diterapkan')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Gagal menerapkan opname')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('cancel_opname')
                    ->label('Batalkan')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Sesi Opname')
                    ->modalDescription(fn (StockOpname $record): string => "Apakah Anda yakin ingin membatalkan sesi opname {$record->opname_number}? Status dokumen akan diubah menjadi Dibatalkan dan saldo stok tidak akan dipengaruhi.")
                    ->modalSubmitActionLabel('Ya, Batalkan Sesi')
                    ->modalCancelActionLabel('Tidak')
                    ->visible(fn (StockOpname $record): bool => $record->isDraft())
                    ->action(function (StockOpname $record): void {
                        $record->update(['status' => StockOpname::STATUS_CANCELLED]);

                        Notification::make()
                            ->title('Sesi opname berhasil dibatalkan')
                            ->warning()
                            ->send();
                    }),
                DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus Dokumen Opname')
                    ->modalDescription(fn (StockOpname $record): string => "Apakah Anda yakin ingin menghapus sesi opname {$record->opname_number}? Data hasil input yang belum diterapkan akan dibersihkan secara permanen.")
                    ->modalSubmitActionLabel('Ya, Hapus')
                    ->modalCancelActionLabel('Tidak')
                    ->visible(fn (StockOpname $record): bool => ! $record->isCompleted()),
            ]);
    }
}
