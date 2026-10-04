<?php

namespace App\Filament\Resources\Banners\Schemas;

use App\Domain\Catalog\Models\Banner;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Tampilan baca-saja untuk detail banner.
 *
 * Dipisah dari Resource utama karena panjangnya. Halaman detail akan jadi
 * sangat tinggi kalau semua field ditumpuk di satu kolom.
 */
class BannerInfolist
{
    public static function make(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Konten')
                    ->schema([
                        TextEntry::make('eyebrow')
                            ->label('Label kecil')
                            ->placeholder('—')
                            ->badge(),

                        TextEntry::make('title')
                            ->label('Judul')
                            ->size('lg')
                            ->weight('bold'),

                        TextEntry::make('subtitle')
                            ->label('Subjudul')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('body')
                            ->label('Teks tambahan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Label Editorial')
                    ->schema([
                        TextEntry::make('stamp_left')
                            ->label('Sudut kiri atas')
                            ->fontFamily('mono')
                            ->placeholder('—'),

                        TextEntry::make('stamp_right')
                            ->label('Sudut kanan atas')
                            ->fontFamily('mono')
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->collapsed(),

                Section::make('Tombol')
                    ->schema([
                        TextEntry::make('cta_label')
                            ->label('Tombol utama')
                            ->placeholder('—'),

                        TextEntry::make('cta_url')
                            ->label('Tujuan')
                            ->placeholder('—')
                            ->icon('heroicon-m-arrow-top-right-on-square'),

                        TextEntry::make('cta2_label')
                            ->label('Tombol kedua')
                            ->placeholder('—'),

                        TextEntry::make('cta2_url')
                            ->label('Tujuan')
                            ->placeholder('—')
                            ->icon('heroicon-m-arrow-top-right-on-square'),
                    ])
                    ->columns(2),

                Section::make('Tampilan')
                    ->schema([
                        TextEntry::make('image_path')
                            ->label('Path gambar')
                            ->fontFamily('mono')
                            ->placeholder('—'),

                        TextEntry::make('image_alt')
                            ->label('Teks alternatif')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('theme')
                            ->label('Warna teks')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'light' => 'Terang (foto gelap)',
                                'dark' => 'Gelap (foto terang)',
                                default => $state,
                            }),

                        TextEntry::make('overlay_opacity')
                            ->label('Gelap layar teks')
                            ->suffix('%'),
                    ])
                    ->columns(3),

                Section::make('Jadwal Tayang')
                    ->description('PRD §3.1: banner bisa dijadwalkan tayang.')
                    ->schema([
                        TextEntry::make('starts_at')
                            ->label('Mulai')
                            ->placeholder('Tayang sejak sekarang')
                            ->dateTime('d M Y H:i'),

                        TextEntry::make('ends_at')
                            ->label('Berhenti')
                            ->placeholder('Tayang terus')
                            ->dateTime('d M Y H:i'),

                        TextEntry::make('status_tayang')
                            ->label('Status sekarang')
                            ->state(function (Banner $record): string {
                                if (! $record->is_active) {
                                    return 'Nonaktif — tidak tampil di beranda';
                                }

                                if ($record->notStarted()) {
                                    return 'Terjadwal — belum tayang';
                                }

                                if ($record->isExpired()) {
                                    return 'Berakhir — tidak tampil lagi';
                                }

                                return 'Tayang sekarang';
                            })
                            ->badge()
                            ->color(fn (string $state): string => match (true) {
                                str_contains($state, 'Nonaktif') => 'gray',
                                str_contains($state, 'Terjadwal') => 'info',
                                str_contains($state, 'Berakhir') => 'danger',
                                default => 'success',
                            }),
                    ])
                    ->columns(3),
            ]);
    }
}
