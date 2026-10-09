<?php

namespace App\Filament\Resources\StockOpnames\Schemas;

use App\Models\Sku;
use App\Models\StockOpname;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class StockOpnameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Informasi Sesi Opname')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('opname_number')
                            ->label('Nomor Opname')
                            ->default(fn () => StockOpname::generateNumber())
                            ->readOnly()
                            ->required(),
                        Select::make('user_id')
                            ->label('Petugas Pemeriksa')
                            ->relationship('auditor', 'name')
                            ->default(fn () => Auth::id())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('status')
                            ->label('Status Sesi')
                            ->default(StockOpname::STATUS_DRAFT)
                            ->readOnly(),
                        Textarea::make('notes')
                            ->label('Catatan Audit')
                            ->placeholder('Contoh: Opname fisik rutin rak jaket & kaos gerai Senaru')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Daftar Item Fisik')
                    ->description('Masukkan hasil penghitungan fisik di rak gudang. Selisih akan dihitung secara otomatis.')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items')
                            ->label('Item SKU')
                            ->addActionLabel('Tambah SKU untuk Diperiksa')
                            ->disabled(fn (?StockOpname $record): bool => $record?->isCompleted() ?? false)
                            ->columnSpanFull()
                            ->schema([
                                Select::make('sku_id')
                                    ->label('Pilih SKU')
                                    ->options(function () {
                                        return Sku::with(['product', 'variant'])
                                            ->get()
                                            ->mapWithKeys(function (Sku $sku) {
                                                $productName = $sku->product?->name ?? 'Produk';
                                                $variantName = $sku->variant?->name ? " ({$sku->variant->name})" : '';

                                                return [$sku->id => "{$sku->sku_code} — {$productName}{$variantName}"];
                                            });
                                    })
                                    ->searchable()
                                    ->distinct()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (?int $state, Set $set, Get $get) {
                                        if (! $state) {
                                            $set('system_stock', 0);
                                            $set('difference', 0);

                                            return;
                                        }

                                        $sku = Sku::find($state);
                                        $systemStock = $sku ? (int) $sku->stock : 0;
                                        $set('system_stock', $systemStock);

                                        $physicalStock = (int) ($get('physical_stock') ?? $systemStock);
                                        $set('physical_stock', $physicalStock);
                                        $set('difference', $physicalStock - $systemStock);
                                    })
                                    ->columnSpan(2),

                                TextInput::make('system_stock')
                                    ->label('Stok Sistem')
                                    ->numeric()
                                    ->readOnly()
                                    ->default(0)
                                    ->required(),

                                TextInput::make('physical_stock')
                                    ->label('Stok Fisik')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (?int $state, Get $get, Set $set) {
                                        $systemStock = (int) ($get('system_stock') ?? 0);
                                        $physicalStock = (int) ($state ?? 0);
                                        $set('difference', $physicalStock - $systemStock);
                                    }),

                                TextInput::make('difference')
                                    ->label('Selisih')
                                    ->numeric()
                                    ->readOnly()
                                    ->default(0)
                                    ->required()
                                    ->helperText('Fisik - Sistem'),

                                TextInput::make('notes')
                                    ->label('Keterangan Item')
                                    ->placeholder('Misal: Reject di rak B / Hilang 1')
                                    ->columnSpanFull(),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
