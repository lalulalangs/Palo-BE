<?php

namespace App\Filament\Resources\Banners\Schemas;

use App\Services\ImageOptimizer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Banner')
                    ->schema([
                        TextInput::make('title')
                            ->label('Judul Banner')
                            ->placeholder('Contoh: Promo Spesial Pendakian Rinjani')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        FileUpload::make('image_url')
                            ->label('Gambar Banner')
                            ->image()
                            ->disk('public')
                            ->directory('banners')
                            ->maxSize(config('image.max_upload_kb'))
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(ImageOptimizer::class)->optimize($file, 'banners', 'public'))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Rekomendasi rasio lanskap lebar (16:9 atau 21:9), maksimal 5MB (JPG/PNG/WEBP). Gambar otomatis dikompres dan dikonversi ke WebP.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Periode Jadwal Tayang')
                    ->description('Tentukan tanggal tayang. Kosongkan jika ingin langsung tayang tanpa batas waktu.')
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Tanggal Mulai Tayang')
                            ->displayFormat('d/m/Y')
                            ->native(false),
                        DatePicker::make('end_date')
                            ->label('Tanggal Berakhir Tayang')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->afterOrEqual('start_date'),
                    ])
                    ->columns(2),

                Section::make('Pengaturan Tampilan')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Jika dinonaktifkan, banner tidak akan tampil di homepage meskipun berada di jadwal tayang.'),
                        Hidden::make('sort_order')
                            ->default(0),
                    ]),
            ]);
    }
}
