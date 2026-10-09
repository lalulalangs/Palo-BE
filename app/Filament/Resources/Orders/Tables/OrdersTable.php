<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label('No. Pesanan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('created_at')
                    ->label('Waktu Checkout')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('shipping_recipient_name')
                    ->label('Penerima')
                    ->searchable()
                    ->description(fn (Order $record): ?string => $record->shipping_phone),

                TextColumn::make('shipping_courier')
                    ->label('Ekspedisi')
                    ->formatStateUsing(fn (Order $record): string => ($record->shipping_courier ?? '-').($record->shipping_service ? " ({$record->shipping_service})" : ''))
                    ->description(fn (Order $record): string => $record->tracking_number ? "Resi: {$record->tracking_number}" : 'Belum ada resi'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Order::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => Order::getStatusColor($state)),

                TextColumn::make('total')
                    ->label('Total Belanja')
                    ->money('IDR', locale: 'id')
                    ->sortable()
                    ->weight('bold')
                    ->summarize(
                        Sum::make()
                            ->money('IDR', locale: 'id')
                            ->label('Total Omset')
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status Pesanan')
                    ->options(Order::getStatusLabels()),

                SelectFilter::make('shipping_courier')
                    ->label('Kurir Ekspedisi')
                    ->options(fn (): array => Order::query()
                        ->whereNotNull('shipping_courier')
                        ->distinct()
                        ->pluck('shipping_courier', 'shipping_courier')
                        ->all()
                    ),

                Filter::make('tracking_status')
                    ->label('Status Resi')
                    ->form([
                        Select::make('has_resi')
                            ->label('Status Resi')
                            ->placeholder('Semua')
                            ->options([
                                'yes' => 'Sudah Ada Resi',
                                'no' => 'Belum Ada Resi',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (($data['has_resi'] ?? null) === 'yes') {
                            return $query->whereNotNull('tracking_number')->where('tracking_number', '!=', '');
                        }

                        if (($data['has_resi'] ?? null) === 'no') {
                            return $query->where(fn (Builder $q) => $q->whereNull('tracking_number')->orWhere('tracking_number', ''));
                        }

                        return $query;
                    }),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')->label('Dari Tanggal'),
                        DatePicker::make('created_until')->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date)
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
