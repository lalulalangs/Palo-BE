<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Sku;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

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
            ->recordTitleAttribute('name')
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
                CreateAction::make(),
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
