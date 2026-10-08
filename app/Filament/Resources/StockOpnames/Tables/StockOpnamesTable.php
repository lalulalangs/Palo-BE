<?php

namespace App\Filament\Resources\StockOpnames\Tables;

use App\Models\StockOpname;
use App\Services\Inventory\StockMovementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->visible(fn (StockOpname $record): bool => ! $record->isCompleted())
                    ->action(function (StockOpname $record, StockMovementService $service): void {
                        try {
                            $service->applyOpname($record, auth()->id());

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
            ]);
    }
}
