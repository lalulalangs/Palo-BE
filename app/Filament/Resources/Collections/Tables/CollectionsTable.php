<?php

namespace App\Filament\Resources\Collections\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CollectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('banner_desktop')
                    ->label('Banner')
                    ->disk('public')
                    ->width(100)
                    ->height(45)
                    ->defaultImageUrl(url('/images/placeholder-banner.png')),
                TextColumn::make('name')
                    ->label('Nama Koleksi')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('badge_label')
                    ->label('Badge')
                    ->badge()
                    ->color('warning')
                    ->placeholder('-'),
                TextColumn::make('products_count')
                    ->label('Jml Produk')
                    ->counts('products')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Aktif' => 'success',
                        'Mendatang' => 'info',
                        'Berakhir' => 'warning',
                        'Nonaktif' => 'gray',
                        default => 'gray',
                    }),
                ToggleColumn::make('is_active')
                    ->label('Aktif'),
                ToggleColumn::make('is_featured')
                    ->label('Featured'),
                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
            ])
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
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
