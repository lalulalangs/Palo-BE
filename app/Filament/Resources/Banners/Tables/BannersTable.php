<?php

namespace App\Filament\Resources\Banners\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class BannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')
                    ->label('Banner')
                    ->disk('public')
                    ->width(120)
                    ->height(50)
                    ->defaultImageUrl(url('/images/placeholder-banner.png')),
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('status_label')
                    ->label('Status Tayang')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Tayang' => 'success',
                        'Mendatang' => 'info',
                        'Kadaluarsa' => 'warning',
                        'Nonaktif' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('start_date')
                    ->label('Mulai Tayang')
                    ->date('d M Y')
                    ->placeholder('Langsung')
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label('Berakhir')
                    ->date('d M Y')
                    ->placeholder('Selamanya')
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Aktif'),
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
