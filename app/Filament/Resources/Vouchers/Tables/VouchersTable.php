<?php

namespace App\Filament\Resources\Vouchers\Tables;

use App\Models\Voucher;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VouchersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode Voucher')
                    ->weight('bold')
                    ->badge()
                    ->copyable()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Voucher::TYPE_PERCENTAGE => 'Persentase',
                        default => 'Nominal',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Voucher::TYPE_PERCENTAGE => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('value')
                    ->label('Potongan')
                    ->state(fn (Voucher $record): string => $record->formattedValue())
                    ->sortable(),

                TextColumn::make('min_purchase')
                    ->label('Min. Belanja')
                    ->state(fn (Voucher $record): string => (float) $record->min_purchase > 0
                        ? $record->formattedMinPurchase()
                        : 'Tanpa Syarat')
                    ->sortable(),

                TextColumn::make('usage_quota')
                    ->label('Pemakaian')
                    ->state(fn (Voucher $record): string => $record->quota > 0
                        ? "{$record->used_count} / {$record->quota}"
                        : "{$record->used_count} / ∞")
                    ->badge()
                    ->color(fn (Voucher $record): string => ($record->quota > 0 && $record->used_count >= $record->quota)
                        ? 'danger'
                        : 'gray'),

                TextColumn::make('period')
                    ->label('Masa Berlaku')
                    ->state(function (Voucher $record): string {
                        if (! $record->start_date && ! $record->end_date) {
                            return 'Selamanya';
                        }
                        $start = $record->start_date ? $record->start_date->format('d/m/Y') : 'Sekarang';
                        $end = $record->end_date ? $record->end_date->format('d/m/Y') : 'Seterusnya';

                        return "{$start} - {$end}";
                    })
                    ->badge()
                    ->color(function (Voucher $record): string {
                        if ($record->isExpired()) {
                            return 'danger';
                        }
                        if (! $record->hasStarted()) {
                            return 'warning';
                        }

                        return 'success';
                    }),

                ToggleColumn::make('is_active')
                    ->label('Aktif'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipe Potongan')
                    ->options([
                        Voucher::TYPE_FIXED => 'Nominal Tetap',
                        Voucher::TYPE_PERCENTAGE => 'Persentase',
                    ]),
                SelectFilter::make('is_active')
                    ->label('Status Aktif')
                    ->options([
                        '1' => 'Aktif',
                        '0' => 'Nonaktif',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
