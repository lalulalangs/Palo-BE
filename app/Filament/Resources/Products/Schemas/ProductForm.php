<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Components\ImageFileUpload;
use App\Models\Product;
use Filament\Actions\Action;
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
                        Select::make('collections')
                            ->label('Koleksi / Series (Opsional)')
                            ->relationship('collections', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Pilih satu atau lebih koleksi yang menaungi produk ini.'),
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
                            ->label('Harga Dasar')
                            ->required()
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0),
                        Textarea::make('description')
                            ->label('Deskripsi Produk')
                            ->placeholder('Tuliskan detail spesifikasi, bahan, atau informasi penting produk...')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Foto Utama Produk')
                    ->description('Unggah 1 foto produk utama (maksimal 5MB, format JPG/PNG/WEBP). Foto otomatis dikompres dan dikonversi ke WebP.')
                    ->schema([
                        Repeater::make('media')
                            ->relationship('media')
                            ->orderColumn('sort_order')
                            ->maxItems(1)
                            ->reorderable(false)
                            ->addActionLabel('Unggah Foto Produk')
                            ->schema([
                                ImageFileUpload::make('url')
                                    ->label('File Foto')
                                    ->disk('public')
                                    ->directory('products')
                                    ->required(),
                                Hidden::make('type')
                                    ->default('image'),
                            ])
                            ->columnSpanFull(),
                    ]),

                Section::make('Status & Visibilitas Produk')
                    ->description('Atur ketersediaan produk di etalase toko dan penyorotan di halaman depan.')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Status Produk (Tampilkan di Toko)')
                            ->helperText('Jika diaktifkan, produk langsung dapat dilihat, dicari, dan dibeli oleh pelanggan di website. Jika nonaktif, produk disimpan sebagai draf/disembunyikan.')
                            ->onColor('success')
                            ->offColor('danger')
                            ->onIcon('heroicon-m-eye')
                            ->offIcon('heroicon-m-eye-slash')
                            ->default(true),
                        Toggle::make('is_featured')
                            ->label('Jadikan Produk Unggulan (Rekomendasi Utama)')
                            ->helperText('Jika diaktifkan, produk ini akan disorot di bagian "Produk Pilihan / Rekomendasi" pada halaman depan (homepage) website.')
                            ->onColor('warning')
                            ->offColor('gray')
                            ->onIcon('heroicon-m-star')
                            ->offIcon('heroicon-m-star')
                            ->default(false),
                    ])
                    ->columns(2),
            ]);
    }
}
