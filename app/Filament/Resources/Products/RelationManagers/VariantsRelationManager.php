<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Actions\CreateVariantMatrix;
use App\Models\Sku;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Varian & Stok Produk';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama Varian')
                    ->placeholder('Contoh: Black / Size M')
                    ->required()
                    ->maxLength(255),
                KeyValue::make('attributes')
                    ->label('Atribut Varian')
                    ->keyLabel('Atribut (misal: color, size)')
                    ->valueLabel('Nilai (misal: Black, M)')
                    ->reorderable(),
                Repeater::make('skus')
                    ->relationship('skus')
                    ->label('Informasi SKU & Stok')
                    ->schema([
                        TextInput::make('sku_code')
                            ->label('Kode SKU')
                            ->placeholder('Contoh: PR-HD-BLK-M')
                            ->required()
                            ->unique(Sku::class, 'sku_code', ignoreRecord: true),
                        TextInput::make('stock')
                            ->label('Jumlah Stok')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('weight_grams')
                            ->label('Berat (gram)')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(350)
                            ->helperText('Dipakai untuk menghitung ongkir. Default 350 gram.'),
                        // Dimensi opsional. Bila ketiganya terisi, kurir menagih
                        // berat terbesar antara aktual dan volumetrik (P x L x T / 6000).
                        TextInput::make('length_cm')
                            ->label('Panjang (cm)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(32767)
                            ->helperText('Opsional. Isi bersama lebar & tinggi untuk berat volumetrik.'),
                        TextInput::make('width_cm')
                            ->label('Lebar (cm)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(32767),
                        TextInput::make('height_cm')
                            ->label('Tinggi (cm)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(32767),
                        TextInput::make('price_override')
                            ->label('Harga Khusus Varian (Override)')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->helperText('Kosongkan jika ingin menggunakan harga dasar produk'),
                        Toggle::make('is_active')
                            ->label('Status Aktif SKU')
                            ->default(true),
                    ])
                    ->columns(2)
                    ->defaultItems(1)
                    ->minItems(1)
                    ->maxItems(1)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel('Varian')
            ->pluralModelLabel('Daftar Varian')
            ->recordTitleAttribute('name')
            // Kolom harga varian memakai placeholder $record->product->base_price;
            // `product` bukan nama kolom tabel sehingga tidak ikut eager load
            // otomatis Filament — tanpa ini terjadi N+1 (1 query per baris).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('product'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Varian')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('sku.sku_code')
                    ->label('Kode SKU')
                    ->searchable()
                    ->badge()
                    ->copyable()
                    ->copyMessage('Kode SKU disalin'),
                TextColumn::make('sku.stock')
                    ->label('Stok')
                    ->sortable()
                    ->badge()
                    ->color(fn (int|string|null $state): string => match (true) {
                        (int) $state <= 0 => 'danger',
                        (int) $state <= 5 => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('sku.price_override')
                    ->label('Harga Varian')
                    ->money('IDR', locale: 'id')
                    ->placeholder(fn ($record) => $record->product ? Number::currency($record->product->base_price, 'IDR', 'id') : '-'),
                IconColumn::make('sku.is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('createMatrix')
                    ->label('Matriks Warna × Ukuran')
                    ->icon('heroicon-o-squares-plus')
                    ->modalHeading('Tambah Varian per Warna')
                    ->modalDescription('Satu warna menghasilkan satu varian untuk setiap ukuran yang dipilih.')
                    ->schema([
                        Repeater::make('colors')
                            ->label('Warna')
                            ->schema([
                                TextInput::make('color')
                                    ->label('Warna')
                                    ->placeholder('Contoh: Hitam')
                                    ->required()
                                    ->maxLength(50),
                                CheckboxList::make('sizes')
                                    ->label('Ukuran yang ready')
                                    ->options(array_combine(CreateVariantMatrix::SIZES, CreateVariantMatrix::SIZES))
                                    ->columns(3)
                                    ->required()
                                    ->minItems(1),
                                TextInput::make('stock')
                                    ->label('Stok (berlaku semua ukuran)')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->default(0)
                                    ->required(),
                                TextInput::make('price_override')
                                    ->label('Harga Override (Rp)')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->minValue(0)
                                    ->helperText('Kosongkan untuk memakai harga dasar produk'),
                                Toggle::make('is_active')
                                    ->label('SKU aktif')
                                    ->default(true),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Tambah warna lain')
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        $product = $livewire->getOwnerRecord();
                        $created = app(CreateVariantMatrix::class)->execute($product, $data['colors'] ?? []);

                        Notification::make()
                            ->success()
                            ->title(count($created).' varian berhasil dibuat')
                            ->send();
                    }),
                CreateAction::make()
                    ->label('Tambah Varian Satuan'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
