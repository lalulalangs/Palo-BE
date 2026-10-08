<?php

namespace App\Filament\Resources\Collections\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Produk dalam Koleksi';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sort_order')
                    ->label('Urutan Prioritas di Koleksi')
                    ->helperText('Angka lebih kecil tampil di urutan teratas.')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Produk')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->placeholder('-'),
                TextColumn::make('base_price')
                    ->label('Harga Dasar')
                    ->formatStateUsing(fn ($state) => Number::currency($state, 'IDR', 'id')),
                TextColumn::make('sort_order')
                    ->label('Urutan Koleksi')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Status Aktif')
                    ->boolean(),
            ])
            ->defaultSort('collection_product.sort_order', 'asc')
            ->headerActions([
                AttachAction::make()
                    ->label('Tambahkan Produk ke Koleksi')
                    ->preloadRecordSelect()
                    ->schema([
                        TextInput::make('sort_order')
                            ->label('Urutan Prioritas')
                            ->numeric()
                            ->default(0),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Ubah Urutan'),
                DetachAction::make()
                    ->label('Keluarkan'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
