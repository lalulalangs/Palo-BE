<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),
                        TextInput::make('slug')
                            ->required()
                            ->unique(Product::class, 'slug', ignoreRecord: true),
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
