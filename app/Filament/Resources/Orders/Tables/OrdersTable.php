<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkActionGroup;
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
use Illuminate\Support\Facades\Cache;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label('No. Pesanan')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search) {
                        $q->where('order_number', 'like', "%{$search}%")
                            ->orWhere('cod_reference_code', 'like', "%{$search}%")
                            ->orWhere('guest_reference_code', 'like', "%{$search}%")
                            ->orWhere('shipping_phone', 'like', "%{$search}%");
                    }))
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->description(fn (Order $record): ?string => match (true) {
                        $record->isCod() && $record->cod_reference_code => "COD: {$record->cod_reference_code}",
                        $record->isWhatsAppOrder() && $record->guest_reference_code => "WA: {$record->guest_reference_code}",
                        default => null,
                    }),

                TextColumn::make('payment_method')
                    ->label('Metode Bayar')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        Order::PAYMENT_METHOD_COD => 'COD',
                        Order::PAYMENT_METHOD_WHATSAPP => 'WhatsApp (VA/QRIS)',
                        Order::PAYMENT_METHOD_ONLINE => 'Online',
                        default => strtoupper($state ?? 'ONLINE'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        Order::PAYMENT_METHOD_COD => 'warning',
                        default => 'info',
                    }),

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
                    ->formatStateUsing(fn (OrderStatus|string $state): string => OrderStatus::labelFor($state))
                    ->color(fn (OrderStatus|string $state): string => OrderStatus::colorFor($state))
                    ->description(fn (Order $record): ?string => $record->hasRefund() ? (Order::getRefundStatusLabels()[$record->refund_status] ?? $record->refund_status) : null),

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
                SelectFilter::make('payment_method')
                    ->label('Metode Pembayaran')
                    ->options([
                        Order::PAYMENT_METHOD_ONLINE => 'Online',
                        Order::PAYMENT_METHOD_COD => 'Cash On Delivery (COD)',
                        Order::PAYMENT_METHOD_WHATSAPP => 'WhatsApp Tamu (VA/QRIS)',
                    ]),

                SelectFilter::make('status')
                    ->label('Status Pesanan')
                    ->options(Order::getStatusLabels()),

                SelectFilter::make('refund_status')
                    ->label('Status Refund')
                    ->options(Order::getRefundStatusLabels()),

                SelectFilter::make('shipping_courier')
                    ->label('Kurir Ekspedisi')
                    // `distinct()` tanpa indeks = full scan, dan closure ini
                    // dievaluasi setiap render. Cache 10 menit sudah cukup:
                    // daftar kurir berubah hanya saat ada order baru.
                    ->options(fn (): array => Cache::remember(
                        'orders:shipping-courier-options',
                        now()->addMinutes(10),
                        fn (): array => Order::query()
                            ->whereNotNull('shipping_courier')
                            ->distinct()
                            ->orderBy('shipping_courier')
                            ->pluck('shipping_courier', 'shipping_courier')
                            ->all(),
                    )),

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
                        $from = $data['created_from'] ?? null;
                        $until = $data['created_until'] ?? null;

                        return $query
                            // Rentang setengah terbuka. `whereDate()` membuat
                            // PostgreSQL menghitung created_at::date sehingga
                            // indeks created_at tidak pernah terpakai.
                            ->when(
                                $from,
                                fn (Builder $q, string $date): Builder => $q->where('created_at', '>=', CarbonImmutable::parse($date)->startOfDay())
                            )
                            ->when(
                                $until,
                                fn (Builder $q, string $date): Builder => $q->where('created_at', '<', CarbonImmutable::parse($date)->addDay()->startOfDay())
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                OrderActions::makeConfirmCodAction(),
                OrderActions::makeMarkAsPaidAction(),
                OrderActions::makeProcessingAction(),
                OrderActions::makeShipAction(),
                OrderActions::makeCompleteAction(),
                OrderActions::makeRequestRefundAction(),
                OrderActions::makeApproveRefundAction(),
                OrderActions::makeRejectRefundAction(),
                OrderActions::makeCompleteRefundAction(),
                OrderActions::makeCancelAction(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    OrderActions::makeBulkProcessingAction(),
                    OrderActions::makeBulkExportAction(),
                ]),
            ]);
    }
}
