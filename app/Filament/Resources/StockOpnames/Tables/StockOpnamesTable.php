<?php

namespace App\Filament\Resources\StockOpnames\Tables;

use App\Models\StockOpname;
use App\Services\Inventory\StockMovementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockOpnamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('opname_number')
                    ->label('No. Dokumen')
                    ->searchable()
                    ->sortable()
                    ->fontFamily(FontFamily::Mono)
                    ->weight('bold')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->copyable()
                    ->copyMessage('Nomor dokumen opname disalin ke clipboard'),

                TextColumn::make('created_at')
                    ->label('Waktu Pembuatan')
                    ->dateTime('d M Y, H:i')
                    ->description(fn (StockOpname $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable(),

                TextColumn::make('auditor.name')
                    ->label('Auditor')
                    ->icon(Heroicon::OutlinedUser)
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Item SKU')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        StockOpname::STATUS_COMPLETED => Heroicon::OutlinedCheckCircle,
                        StockOpname::STATUS_CANCELLED => Heroicon::OutlinedXCircle,
                        default => Heroicon::OutlinedClock,
                    })
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
                    ->dateTime('d M Y, H:i')
                    ->placeholder('Belum Diterapkan')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status Audit')
                    ->options([
                        StockOpname::STATUS_DRAFT => 'Draft (Pengecekan)',
                        StockOpname::STATUS_COMPLETED => 'Selesai Diterapkan',
                        StockOpname::STATUS_CANCELLED => 'Dibatalkan',
                    ]),
                SelectFilter::make('user_id')
                    ->label('Auditor')
                    ->relationship('auditor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('apply_opname')
                    ->label('Terapkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalIcon(Heroicon::OutlinedScale)
                    ->modalHeading('Terapkan Penyesuaian Stok Opname')
                    ->modalDescription('Apakah Anda yakin ingin menerapkan hasil opname fisik ini? Saldo stok SKU akan langsung disesuaikan dan log jurnal mutasi penyesuaian akan dibukukan ke sistem.')
                    ->modalSubmitActionLabel('Ya, Terapkan Sekarang')
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
