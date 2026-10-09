<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Models\OrderItem;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan Transaksi')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('order_number')
                                    ->label('Nomor Pesanan')
                                    ->fontFamily(FontFamily::Mono)
                                    ->weight('bold')
                                    ->copyable(),

                                TextEntry::make('status')
                                    ->label('Status Pesanan')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => Order::getStatusLabels()[$state] ?? $state)
                                    ->color(fn (string $state): string => Order::getStatusColor($state)),

                                TextEntry::make('created_at')
                                    ->label('Waktu Checkout')
                                    ->dateTime('d/m/Y H:i:s'),

                                TextEntry::make('user.name')
                                    ->label('Akun Pembeli')
                                    ->placeholder('Guest / Pelanggan'),
                            ]),
                    ]),

                Tabs::make('OrderTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Logistik & Pemenuhan')
                            ->icon(Heroicon::OutlinedTruck)
                            ->schema([
                                Section::make('Informasi Ekspedisi & Resi')
                                    ->schema([
                                        Grid::make(3)
                                            ->schema([
                                                TextEntry::make('shipping_courier')
                                                    ->label('Kurir Ekspedisi')
                                                    ->weight('bold')
                                                    ->placeholder('-'),

                                                TextEntry::make('shipping_service')
                                                    ->label('Layanan Pengiriman')
                                                    ->placeholder('-'),

                                                TextEntry::make('tracking_number')
                                                    ->label('Nomor Resi Pelacakan')
                                                    ->fontFamily(FontFamily::Mono)
                                                    ->weight('bold')
                                                    ->copyable()
                                                    ->placeholder('Belum ada resi pengiriman'),
                                            ]),
                                    ]),

                                Section::make('Data Penerima & Alamat Tujuan')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('shipping_recipient_name')
                                                    ->label('Nama Penerima')
                                                    ->weight('bold'),

                                                TextEntry::make('shipping_phone')
                                                    ->label('Nomor Telepon / WhatsApp')
                                                    ->copyable(),
                                            ]),

                                        TextEntry::make('shipping_full_address')
                                            ->label('Alamat Lengkap Pengiriman (Snapshot)')
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Item Pesanan')
                            ->icon(Heroicon::OutlinedShoppingBag)
                            ->schema([
                                Section::make('Daftar Produk yang Dipesan')
                                    ->description('Data snapshot produk saat transaksi terjadi — tidak terpengaruh jika katalog produk berubah.')
                                    ->schema([
                                        RepeatableEntry::make('items')
                                            ->label('')
                                            ->schema([
                                                ImageEntry::make('thumbnail_url')
                                                    ->label('Foto')
                                                    ->state(fn (OrderItem $record): ?string => $record->getThumbnailUrl())
                                                    ->disk('public')
                                                    ->square()
                                                    ->placeholder('-'),

                                                TextEntry::make('product_name_snapshot')
                                                    ->label('Nama Produk')
                                                    ->weight('bold'),

                                                TextEntry::make('variant_name_snapshot')
                                                    ->label('Varian')
                                                    ->placeholder('-'),

                                                TextEntry::make('sku_code_snapshot')
                                                    ->label('Kode SKU')
                                                    ->fontFamily(FontFamily::Mono),

                                                TextEntry::make('price_snapshot')
                                                    ->label('Harga Satuan')
                                                    ->money('IDR', locale: 'id'),

                                                TextEntry::make('quantity')
                                                    ->label('Jumlah (Qty)')
                                                    ->alignCenter()
                                                    ->badge(),

                                                TextEntry::make('subtotal')
                                                    ->label('Subtotal')
                                                    ->money('IDR', locale: 'id')
                                                    ->weight('bold'),
                                            ])
                                            ->columns(7),
                                    ]),
                            ]),

                        Tab::make('Finansial & Pembayaran')
                            ->icon(Heroicon::OutlinedCreditCard)
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Section::make('Rincian Biaya Transaksi')
                                            ->schema([
                                                TextEntry::make('subtotal')
                                                    ->label('Subtotal Produk')
                                                    ->money('IDR', locale: 'id'),

                                                TextEntry::make('discount_amount')
                                                    ->label('Potongan Diskon / Voucher')
                                                    ->money('IDR', locale: 'id'),

                                                TextEntry::make('shipping_cost')
                                                    ->label('Biaya Pengiriman (Ongkir)')
                                                    ->money('IDR', locale: 'id'),

                                                TextEntry::make('total')
                                                    ->label('Grand Total Tagihan')
                                                    ->money('IDR', locale: 'id')
                                                    ->weight('bold')
                                                    ->size(TextSize::Large)
                                                    ->color('primary'),
                                            ]),

                                        Section::make('Status Payment Gateway')
                                            ->schema([
                                                TextEntry::make('payment.gateway')
                                                    ->label('Gateway Pembayaran')
                                                    ->placeholder('-'),

                                                TextEntry::make('payment.payment_method')
                                                    ->label('Metode Pembayaran')
                                                    ->placeholder('-'),

                                                TextEntry::make('payment.status')
                                                    ->label('Status Transaksi Gateway')
                                                    ->badge()
                                                    ->placeholder('Belum ada transaksi'),

                                                TextEntry::make('payment.paid_at')
                                                    ->label('Waktu Pelunasan')
                                                    ->dateTime('d/m/Y H:i:s')
                                                    ->placeholder('-'),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Linimasa Audit Status')
                            ->icon(Heroicon::OutlinedClock)
                            ->schema([
                                Section::make('Jejak Riwayat Status Pesanan')
                                    ->description('Seluruh pergantian status tercatat permanen beserta identitas petugas atau sistem.')
                                    ->schema([
                                        RepeatableEntry::make('statusHistories')
                                            ->label('')
                                            ->schema([
                                                TextEntry::make('created_at')
                                                    ->label('Waktu Transisi')
                                                    ->dateTime('d/m/Y H:i:s'),

                                                TextEntry::make('from_status')
                                                    ->label('Status Asal')
                                                    ->badge()
                                                    ->formatStateUsing(fn (?string $state): string => $state ? (Order::getStatusLabels()[$state] ?? $state) : 'Checkout Baru'),

                                                TextEntry::make('to_status')
                                                    ->label('Status Baru')
                                                    ->badge()
                                                    ->formatStateUsing(fn (string $state): string => Order::getStatusLabels()[$state] ?? $state)
                                                    ->color(fn (string $state): string => Order::getStatusColor($state)),

                                                TextEntry::make('changedBy.name')
                                                    ->label('Aktor Pelaksana')
                                                    ->placeholder('Sistem / Otomatis'),

                                                TextEntry::make('note')
                                                    ->label('Catatan Transisi')
                                                    ->columnSpanFull()
                                                    ->placeholder('-'),
                                            ])
                                            ->columns(4),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
