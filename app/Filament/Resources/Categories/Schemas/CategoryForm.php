<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('parent_id')
                    ->label('Parent Category')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->label('Nama Kategori')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                        if ($operation !== 'create') {
                            return;
                        }

                        $set('slug', Str::slug($state ?? ''));
                    }),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(Category::class, 'slug', ignoreRecord: true)
                    ->rules(['regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'])
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state ? Str::slug($state) : null)
                    ->suffixAction(
                        Action::make('regenerateSlug')
                            ->icon('heroicon-m-arrow-path')
                            ->tooltip('Generate ulang slug dari nama')
                            ->action(function (Set $set, Get $get): void {
                                $name = $get('name');
                                if ($name) {
                                    $set('slug', Str::slug($name));
                                }
                            })
                    )
                    ->helperText('Otomatis dibuat saat kategori baru ditambahkan. Klik ikon refresh jika ingin sinkronkan dengan nama saat ini.'),
            ]);
    }
}
