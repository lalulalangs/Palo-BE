<?php

namespace App\Filament\Resources\HeroSliders\Schemas;

use App\Filament\Components\ImageFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class HeroSliderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Konten Slider')
                    ->schema([
                        TextInput::make('series_tag')
                            ->label('Series Tag')
                            ->placeholder('FORM. 01 — RINJANI SERIES')
                            ->maxLength(255),
                        TextInput::make('location_tag')
                            ->label('Location Tag')
                            ->placeholder('SENARU / 600 MDPL')
                            ->maxLength(255),
                        TextInput::make('badge')
                            ->placeholder('KOLEKSI BUSANA & CENDERAMATA')
                            ->maxLength(255),
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                        TextInput::make('image_alt')
                            ->label('Image Alt Text')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Gambar')
                    ->description('Gambar desktop wajib, mobile opsional (maksimal 5MB, format JPG/PNG/WEBP). Gambar otomatis dikompres dan dikonversi ke WebP di disk public/heroes.')
                    ->schema([
                        ImageFileUpload::make('image_desktop')
                            ->label('Gambar Desktop')
                            ->disk('public')
                            ->directory('heroes')
                            ->required(),
                        ImageFileUpload::make('image_mobile')
                            ->label('Gambar Mobile')
                            ->disk('public')
                            ->directory('heroes'),
                    ])
                    ->columns(2),

                Section::make('Tombol Aksi & Tampilan')
                    ->schema([
                        TextInput::make('primary_btn_label')
                            ->default('LIHAT KATALOG')
                            ->maxLength(50),
                        TextInput::make('primary_btn_url')
                            ->required()
                            ->placeholder('/katalog/rinjani-series')
                            ->maxLength(255),
                        TextInput::make('secondary_btn_label')
                            ->default('CERITA SENARU')
                            ->maxLength(50),
                        TextInput::make('secondary_btn_url')
                            ->placeholder('/cerita/senaru')
                            ->maxLength(255),
                        TextInput::make('sort_order')
                            ->label('Urutan Tampil')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
