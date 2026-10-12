<?php

namespace App\Filament\Resources\Vouchers\Schemas;

use App\Models\Voucher;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VoucherForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Utama Voucher')
                    ->description('Tentukan kode kupon promo dan nilai potongan diskon.')
                    ->schema([
                        TextInput::make('code')
                            ->label('Kode Voucher')
                            ->placeholder('Contoh: RINJANIHEMAT')
                            ->required()
                            ->maxLength(50)
                            ->unique(Voucher::class, 'code', ignoreRecord: true)
                            ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) $state)))
                            ->columnSpan(2),
                        Select::make('type')
                            ->label('Tipe Potongan')
                            ->options([
                                Voucher::TYPE_FIXED => 'Nominal Tetap (Rp)',
                                Voucher::TYPE_PERCENTAGE => 'Persentase (%)',
                            ])
                            ->default(Voucher::TYPE_FIXED)
                            ->required()
                            ->live(),
                        TextInput::make('value')
                            ->label('Nilai Potongan')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->prefix(fn ($get) => $get('type') === Voucher::TYPE_PERCENTAGE ? '%' : 'Rp')
                            ->helperText(fn ($get) => $get('type') === Voucher::TYPE_PERCENTAGE
                                ? 'Persentase potongan harga dari subtotal pesanan.'
                                : 'Nominal potongan rupiah.'),
                    ])
                    ->columns(2),

                Section::make('Ketentuan & Batasan Kuota')
                    ->description('Atur syarat minimum belanja dan batas maksimal penggunaan kuota.')
                    ->schema([
                        TextInput::make('min_purchase')
                            ->label('Syarat Minimum Belanja')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Minimal subtotal pesanan. Isi 0 jika tanpa syarat minimum belanja.')
                            ->columnSpan(2),
                        TextInput::make('quota')
                            ->label('Batas Kuota Pemakaian')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Maksimal kali transaksi yang dapat menggunakan voucher ini. Isi 0 jika tanpa batas kuota.'),
                        TextInput::make('used_count')
                            ->label('Kuota Terpakai Saat Ini')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation) => $operation === 'edit'),
                    ])
                    ->columns(2),

                Section::make('Masa Berlaku & Status')
                    ->description('Jadwalkan tanggal mulai dan berakhir berlakunya promo.')
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Tanggal Mulai Berlaku')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->placeholder('Langsung berlaku'),
                        DatePicker::make('end_date')
                            ->label('Tanggal Berakhir')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->afterOrEqual('start_date')
                            ->placeholder('Berlaku selamanya'),
                        Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Jika dinonaktifkan, voucher tidak dapat digunakan di keranjang atau checkout.'),
                    ])
                    ->columns(2),
            ]);
    }
}
