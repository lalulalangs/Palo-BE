<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Dasar Produk')
                    ->schema([
                        Select::make('category_id')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('name')
                            ->label('Nama Produk')
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
                            ->unique(Product::class, 'slug', ignoreRecord: true)
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
                            ->helperText('Otomatis dibuat saat produk baru ditambahkan. Klik ikon refresh jika ingin sinkronkan dengan nama saat ini.'),
                        TextInput::make('base_price')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0),
                        Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                        Toggle::make('is_featured')
                            ->default(false),
                        Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Galeri Media Produk')
                    ->description('Unggah gambar produk (maksimal 2MB, format JPG/PNG/WEBP). Gambar pertama otomatis menjadi foto utama/thumbnail. Tarik untuk mengubah urutan.')
                    ->schema([
                        Repeater::make('media')
                            ->relationship('media')
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->addActionLabel('Tambah Gambar Produk')
                            ->schema([
                                FileUpload::make('url')
                                    ->label('Foto Produk')
                                    ->image()
                                    ->disk('public')
                                    ->directory('products')
                                    ->maxSize(2048)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->required(),
                                Hidden::make('type')
                                    ->default('image'),
                            ])
                            ->grid([
                                'default' => 1,
                                'sm' => 2,
                                'md' => 3,
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
