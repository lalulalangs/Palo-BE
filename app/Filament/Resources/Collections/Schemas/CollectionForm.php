<?php

namespace App\Filament\Resources\Collections\Schemas;

use App\Models\Collection;
use App\Services\ImageOptimizer;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CollectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Dasar Koleksi')
                    ->description('Detail nama, identitas, dan narasi cerita di balik koleksi produk.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Koleksi')
                            ->placeholder('Contoh: DECADE (10th Anniversary)')
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
                            ->label('Slug URL')
                            ->required()
                            ->maxLength(255)
                            ->unique(Collection::class, 'slug', ignoreRecord: true)
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
                            ->helperText('URL publik untuk halaman koleksi: /collections/{slug}'),
                        TextInput::make('tagline')
                            ->label('Tagline / Subjudul')
                            ->placeholder('Contoh: A Decade of Rugged Mountain Heritage')
                            ->maxLength(255),
                        TextInput::make('badge_label')
                            ->label('Badge / Label Khusus')
                            ->placeholder('Contoh: 10th Anniversary / Limited Drop')
                            ->maxLength(50)
                            ->helperText('Label eksklusif yang disematkan pada kartu produk dan header koleksi.'),
                        Textarea::make('description')
                            ->label('Storytelling / Narasi Koleksi')
                            ->placeholder('Ceritakan kisah inspirasi, filosofi desain, dan material eksklusif koleksi ini...')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Visual Banner Koleksi')
                    ->description('Media visual utama yang memperkuat storytelling koleksi di storefront.')
                    ->schema([
                        FileUpload::make('banner_desktop')
                            ->label('Banner Desktop')
                            ->image()
                            ->disk('public')
                            ->directory('collections')
                            ->maxSize(config('image.max_upload_kb', 5120))
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(ImageOptimizer::class)->optimize($file, 'collections', 'public'))
                            ->helperText('Disarankan rasio lebar (16:9 / 21:9) resolusi tinggi, maksimal 5MB. Gambar otomatis dikompres dan dikonversi ke WebP.'),
                        FileUpload::make('banner_mobile')
                            ->label('Banner Mobile (Opsional)')
                            ->image()
                            ->disk('public')
                            ->directory('collections')
                            ->maxSize(config('image.max_upload_kb', 5120))
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(ImageOptimizer::class)->optimize($file, 'collections', 'public'))
                            ->helperText('Banner vertikal optimal untuk tampilan layar handphone, maksimal 5MB. Gambar otomatis dikompres dan dikonversi ke WebP.'),
                    ])
                    ->columns(2),

                Section::make('Jadwal & Visibilitas')
                    ->description('Atur periode penayangan koleksi dan penyorotan di etalase utama.')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->helperText('Jika aktif, koleksi dapat dilihat dan diakses oleh pembeli.')
                            ->onColor('success')
                            ->offColor('danger')
                            ->default(true),
                        Toggle::make('is_featured')
                            ->label('Sorot di Beranda (Featured)')
                            ->helperText('Tampilkan koleksi ini di sorotan utama beranda website.')
                            ->onColor('warning')
                            ->offColor('gray')
                            ->default(false),
                        DateTimePicker::make('published_at')
                            ->label('Mulai Tayang (Opsional)')
                            ->placeholder('Langsung Tayang')
                            ->helperText('Biarkan kosong jika ingin langsung tayang.'),
                        DateTimePicker::make('ended_at')
                            ->label('Berakhir Tayang (Opsional)')
                            ->placeholder('Selamanya')
                            ->helperText('Isi tanggal jika koleksi ini edisi terbatas (time-limited drop).'),
                        TextInput::make('sort_order')
                            ->label('Prioritas Urutan')
                            ->numeric()
                            ->default(0)
                            ->helperText('Angka lebih kecil tampil lebih awal pada daftar koleksi.'),
                    ])
                    ->columns(2),

                Section::make('Optimasi Mesin Pencari (SEO)')
                    ->description('Metadata untuk pencarian Google dan pratinjau media sosial (Open Graph).')
                    ->collapsed()
                    ->schema([
                        TextInput::make('seo_meta.meta_title')
                            ->label('Meta Title')
                            ->placeholder('Judul di hasil pencarian Google')
                            ->maxLength(70),
                        Textarea::make('seo_meta.meta_description')
                            ->label('Meta Description')
                            ->placeholder('Ringkasan deskripsi yang menarik untuk Google...')
                            ->rows(2)
                            ->maxLength(160)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }
}
