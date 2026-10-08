<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Models\StockMovement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('sku.sku_code')
                    ->label('Kode SKU')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('sku.product.name')
                    ->label('Produk')
                    ->searchable(),
                TextColumn::make('sku.variant.name')
                    ->label('Varian')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->label('Tipe Mutasi')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StockMovement::getTypeLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        StockMovement::TYPE_RESTOCK => 'success',
                        StockMovement::TYPE_SALE => 'danger',
                        StockMovement::TYPE_CANCELLATION => 'info',
                        StockMovement::TYPE_OPNAME_ADJUSTMENT => 'warning',
                        StockMovement::TYPE_MANUAL_ADJUSTMENT => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('quantity_change')
                    ->label('Perubahan')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state)
                    ->color(fn (int $state): string => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('stock_before')
                    ->label('Sebelum')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('stock_after')
                    ->label('Sesudah')
                    ->numeric()
                    ->weight('medium')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('Sistem Otomatis')
                    ->searchable(),
                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(45)
                    ->tooltip(fn (StockMovement $record): ?string => $record->notes),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipe Mutasi')
                    ->options(StockMovement::getTypeLabels()),
                SelectFilter::make('sku_id')
                    ->label('Filter SKU')
                    ->relationship('sku', 'sku_code')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
