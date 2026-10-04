<?php

namespace App\Filament\Resources\Banners;

use App\Domain\Catalog\Models\Banner;
use App\Domain\Catalog\Models\ProductMedia;
use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Filament\Resources\Banners\Schemas\BannerInfolist;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Banner hero halaman depan.
 *
 * ===================================================================
 *  KENAPA RESOURCE INI PENTING
 * ===================================================================
 * Tanpa resource ini, banner hanya bisa diubah lewat `php artisan tinker`
 * atau seeder — artinya setiap revisi copy headline mengharuskan akses
 * server. Padahal PRD §3.1 justru meminta:
 *
 *   "Hero banner (carousel) dikelola dinamis oleh Admin, mendukung
 *    penjadwalan tayang (start/end date)."
 *
 * AC §3.1: "Given Admin menonaktifkan sebuah banner, When pengunjung membuka
 * homepage, Then banner tersebut tidak muncul."
 *
 * Banner memakai gaya editorial lookbook, jadi form dibagi per bagian
 * (konten, elemen editorial, tombol, gambar, jadwal) — bukan satu form
 * panjang. Admin lebih mudah mengisi bagian per bagian.
 */
class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'Banner';

    protected static ?string $pluralModelLabel = 'Banner';

    protected static ?string $navigationLabel = 'Banner Hero';

    protected static string|\UnitEnum|null $navigationGroup = 'Konten';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'banner';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // ---------------------------------------------------------
                // Konten utama
                // ---------------------------------------------------------
                Section::make('Konten')
                    ->description('Teks besar yang tampil di tengah hero.')
                    ->schema([
                        TextInput::make('title')
                            ->label('Judul')
                            ->required()
                            ->maxLength(255)
                            // Judul juga jadi alt text cadangan. PRD §4.4
                            // mensyaratkan JSON-LD; gambar tanpa alt tidak
                            // terbaca mesin pencari maupun pembaca layar.
                            ->helperText('Dipakai juga sebagai alt text gambar bila alt khusus belum diisi.'),

                        TextInput::make('eyebrow')
                            ->label('Label kecil di atas judul')
                            ->maxLength(160)
                            ->placeholder('Koleksi Busana & Cenderamata')
                            ->helperText('Tampil sebagai pill mono di atas judul. Opsional.'),

                        Textarea::make('subtitle')
                            ->label('Subjudul')
                            ->rows(2)
                            ->maxLength(255),

                        Textarea::make('body')
                            ->label('Teks tambahan')
                            ->rows(3)
                            ->helperText('Opsional. Tampil di bawah subjudul.'),
                    ]),

                // ---------------------------------------------------------
                // Elemen editorial lookbook
                // ---------------------------------------------------------
                Section::make('Label Editorial')
                    ->description('Label mono kecil di sudut hero. Kosongkan bila tidak dipakai.')
                    ->schema([
                        TextInput::make('stamp_left')
                            ->label('Label sudut kiri atas')
                            ->maxLength(120)
                            ->placeholder('FORM. 01 — RINJANI SERIES'),

                        TextInput::make('stamp_right')
                            ->label('Label sudut kanan atas')
                            ->maxLength(120)
                            ->placeholder('SENARU / 601 MDPL')
                            ->helperText('Tahun batch & elevasi ditulis di sini, bukan di kode.'),
                    ])
                    ->collapsible()
                    ->collapsed(),

                // ---------------------------------------------------------
                // Tombol
                // ---------------------------------------------------------
                Section::make('Tombol')
                    ->description('Hero editorial punya sepasang tombol.')
                    ->schema([
                        TextInput::make('cta_label')
                            ->label('Tombol utama — teks')
                            ->maxLength(120),

                        TextInput::make('cta_url')
                            ->label('Tombol utama — tujuan')
                            ->maxLength(255)
                            ->helperText('Contoh: /produk'),

                        TextInput::make('cta2_label')
                            ->label('Tombol kedua — teks')
                            ->maxLength(120),

                        TextInput::make('cta2_url')
                            ->label('Tombol kedua — tujuan')
                            ->maxLength(255),
                    ]),

                // ---------------------------------------------------------
                // Gambar
                // ---------------------------------------------------------
                Section::make('Gambar')
                    ->description('Disimpan di Object Storage (PRD §3.2).')
                    ->schema([
                        // Dipilih dari daftar, BUKAN diketik bebas.
                        //
                        // Kalau diketik bebas, salah ketik satu karakter membuat
                        // gambar hilang, dan gejalanya di beranda hanya
                        // "banner gelap tanpa foto" — tanpa error di mana pun.
                        // Dengan Select, path yang dipakai pasti benar-benar
                        // ada di object storage.
                        Select::make('image_path')
                            ->label('Gambar banner')
                            ->options(self::pilihanGambar())
                            ->searchable()
                            ->live()
                            ->placeholder('Pilih foto dari katalog')
                            ->helperText('Hanya foto produk yang sudah ada di storage. Untuk foto baru, unggah lewat menu Produk dulu.'),

                        TextInput::make('image_alt')
                            ->label('Teks alternatif')
                            ->maxLength(255)
                            ->helperText('Deskripsikan foto, bukan "gambar". Wajib untuk SEO & aksesibilitas.'),

                        Select::make('theme')
                            ->label('Warna teks di atas foto')
                            ->options([
                                'light' => 'Terang (untuk foto gelap)',
                                'dark' => 'Gelap (untuk foto terang)',
                            ])
                            ->default('light')
                            ->required()
                            ->helperText('Pilih "Gelap" kalau foto banner Anda terang — kalau tidak, judulnya tidak terbaca.'),

                        Slider::make('overlay_opacity')
                            ->label('Gelap layar teks (%)')
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(5)
                            ->default(45)
                            ->required()
                            ->helperText('Lapis gelap di atas foto. Naikkan kalau teks masih sulit dibaca. Jangan 0 tanpa alasan.'),
                    ]),

                // ---------------------------------------------------------
                // Penjadwalan tayang
                // ---------------------------------------------------------
                Section::make('Jadwal Tayang')
                    ->description('PRD §3.1: banner mendukung penjadwalan start/end.')
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label('Mulai tayang')
                            ->seconds(false)
                            ->helperText('Kosongkan = tayang sejak sekarang.'),

                        DateTimePicker::make('ends_at')
                            ->label('Berhenti tayang')
                            ->seconds(false)
                            ->helperText('Kosongkan = tayang terus.'),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->helperText('Nonaktifkan untuk menyembunyikan banner tanpa menghapusnya (AC §3.1).'),

                        TextInput::make('sort_order')
                            ->label('Urutan')
                            ->numeric()
                            ->default(0)
                            ->helperText('Angka kecil tampil lebih dulu di carousel hero.'),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BannerInfolist::make($schema);
    }

    /**
     * Daftar path gambar yang benar-benar tersedia, diambil dari `product_media`.
     *
     * Label dibuat informatif: nama produk + ukuran file, supaya admin tidak
     * perlu mengingat nama file.
     *
     * @return array<string, string>
     */
    protected static function pilihanGambar(): array
    {
        $opsi = [];

        $media = ProductMedia::query()
            ->with('product')
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->get();

        foreach ($media as $item) {
            $produk = $item->product?->name ?? 'Tanpa produk';
            $label = sprintf('%s — %s', $produk, $item->path);

            // Path yang sama bisa muncul lebih dari sekali (satu foto dipakai
            // beberapa banner). Kunci array otomatis menimpanya dengan label
            // yang sama, jadi aman.
            $opsi[$item->path] = $label;
        }

        return $opsi;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Foto')
                    // Path relatif terhadap bucket, jadi kolom ini harus
                    // harus lewat URL penuh di frontend.
                    ->disk('public')
                    ->height(44),

                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Banner $r) => $r->eyebrow),

                TextColumn::make('stamp_left')
                    ->label('Label kiri')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('stamp_right')
                    ->label('Label kanan')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                // Jadwal tayang dihitung, bukan disimpan — supaya tidak
                // mungkin tidak sinkron dengan starts_at/ends_at.
                TextColumn::make('jadwal')
                    ->label('Jadwal')
                    ->state(function (Banner $record): string {
                        if (! $record->is_active) {
                            return 'Nonaktif';
                        }

                        $now = now();
                        if ($record->notStarted()) {
                            return 'Terjadwal: '.optional($record->starts_at)->translatedFormat('d M Y H:i');
                        }

                        if ($record->isExpired()) {
                            return 'Berakhir';
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

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
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

    public static function getPages(): array
    {
        return [
            'index' => ListBanners::route('/'),
            'create' => CreateBanner::route('/create'),
            'edit' => EditBanner::route('/{record}/edit'),
        ];
    }
}
