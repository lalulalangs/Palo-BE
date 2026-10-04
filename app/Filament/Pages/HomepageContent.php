<?php

namespace App\Filament\Pages;

use App\Domain\Shared\Models\AppSetting;
use App\Support\DriveAssets;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Konten beranda.
 *
 * ===================================================================
 *  DELAPAN SECTION, SATU FORM
 * ===================================================================
 * Beranda punya delapan section. Masing-masing punya beberapa field yang
 * selalu travels bersama (judul + subjudul + tombolnya), jadi di sini
 * masing-masing disimpan sebagai satu key `home.*` berisi objek.
 *
 * Halaman admin terpisah dari `AppSettingResource` karena isinya bukan
 * "daftar semua kunci aplikasi", melainkan "isi beranda" — dan admin yang
 * datang untuk mengubah tagline promo tidak perlu melihat 21 baris
 * technical setting untuk mencarinya.
 *
 * ===================================================================
 *  MENGAPA FIELD BUKAN TEKS BEBAS
 * ===================================================================
 * Semua pilihan gambar/video diambil dari `DriveAssets`, yang membaca
 * manifest hasil `scripts/prepare_drive_assets.py`. Admin tidak bisa
 * mengetik path yang salah — yang gejalanya di beranda cuma "kotak kosong"
 * tanpa error di mana pun.
 *
 * ===================================================================
 *  ISI PLACEHOLDER TIDAK DIHAPUS OTOMATIS
 * ===================================================================
 * Nilai berbentuk `[... dari Admin]` dibiarkan apa adanya. Kalau form
 * membersihkannya diam-diam, admin akan mengira informasinya sudah
 * tercatat padahal tidak.
 */
class HomepageContent extends Page
{
    protected static ?string $navigationLabel = 'Konten Beranda';

    protected static string|\UnitEnum|null $navigationGroup = 'Konten';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Konten Beranda';

    protected static ?string $slug = 'konten-beranda';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Sama seperti `AppSettingPolicy::update()`: menulis konten yang
     * dilihat publik adalah keputusan brand, jadi khusus Superadmin.
     */
    public static function canAccess(): bool
    {
        return Gate::check('updateAny', [AppSetting::class]);
    }

    public function mount(): void
    {
        $this->form->fill([
            'benefit' => $this->raw('home.benefit'),
            'film' => $this->raw('home.film'),
            'lookbook' => $this->raw('home.lookbook'),
            'manifesto' => $this->raw('home.manifesto'),
            'gear' => $this->raw('home.gear'),
            'promo' => $this->raw('home.promo'),
            'store' => $this->raw('home.store'),
            'bulk' => $this->raw('home.bulk'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        /*
         * `getSchema('form')`, BUKAN `$this->form`.
         *
         * `$this->form` memicu `__get()`, dan `__get()` Filament hanya
         * menjawab saat Filament TIDAK sedang menyusun schema — padahal
         * `content()` sendiri sedang disusun oleh `cacheSchema('content')`.
         * Hasilnya `PropertyNotFoundException` dan halaman 500.
         */
        return $schema->components([$this->getSchema('form')]);
    }

    public function form(Schema $schema): Schema
    {
        $gambar = DriveAssets::imageOptions();

        return $schema
            ->statePath('data')
            ->components([
                // ---------------------------------------------------------
                // 1. Pita benefit & voucher
                // ---------------------------------------------------------
                Section::make('Pita Benefit & Voucher')
                    ->description('Baris tepat di bawah hero. Kosongkan `Kode Voucher` kalau belum ada promo yang berlaku — kode karangan akan membuat pelanggan benar-benar mencoba kode yang tidak ada.')
                    ->schema([
                        TextInput::make('benefit.title')
                            ->label('Judul benefit')
                            ->maxLength(255),

                        Textarea::make('benefit.body')
                            ->label('Keterangan')
                            ->rows(2)
                            ->maxLength(500),

                        TextInput::make('benefit.voucher_code')
                            ->label('Kode voucher')
                            ->maxLength(60)
                            ->helperText('Kosongkan kalau belum ada promo aktif. Tampilannya persis seperti yang diketik di sini.'),

                        TextInput::make('benefit.voucher_note')
                            ->label('Catatan kode voucher')
                            ->maxLength(255)
                            ->helperText('Opsional. Contoh: "Berlaku sampai 31Desember 2026."'),

                        TextInput::make('benefit.cta_label')
                            ->label('Teks tombol WhatsApp')
                            ->maxLength(120)
                            ->helperText('Tautannya ke nomor WhatsApp CS dari pengaturan toko.'),
                    ])
                    ->columns(1),

                // ---------------------------------------------------------
                // 2. Opening film
                // ---------------------------------------------------------
                Section::make('Opening Film')
                    ->description('Video vertikal + teks visual essay.')
                    ->schema([
                        Select::make('film.video_path')
                            ->label('Berkas video')
                            ->options(fn (): array => DriveAssets::videoOptions())
                            ->searchable()
                            ->placeholder('Pilih video'),

                        Select::make('film.poster_path')
                            ->label('Poster')
                            ->options(fn (): array => DriveAssets::posterOptions())
                            ->searchable()
                            ->helperText('Gambar yang tampil sebelum video diputar.'),

                        TextInput::make('film.chapter')
                            ->label('Label chapter')
                            ->maxLength(60),

                        TextInput::make('film.tag')
                            ->label('Tag pojok')
                            ->maxLength(60),

                        TextInput::make('film.caption')
                            ->label('Keterangan film')
                            ->maxLength(255),

                        TextInput::make('film.spec')
                            ->label('Spesifikasi')
                            ->maxLength(120)
                            ->helperText('Harus jujur. Berkas hasil pipeline adalah 720x1280, jadi jangan tulis "4K".'),

                        TextInput::make('film.eyebrow')
                            ->label('Label kecil')
                            ->maxLength(160),

                        TextInput::make('film.title')
                            ->label('Judul visual essay')
                            ->maxLength(255),

                        Textarea::make('film.body')
                            ->label('Isi visual essay')
                            ->rows(4)
                            ->maxLength(2000),

                        TextInput::make('film.cta_primary_label')
                            ->label('Teks tombol')
                            ->maxLength(120),

                        TextInput::make('film.cta_primary_url')
                            ->label('Tujuan tombol')
                            ->maxLength(255),

                        Repeater::make('film.facts')
                            ->label('Dua fakta ringkas')
                            ->schema([
                                TextInput::make('label')
                                    ->label('Label')
                                    ->maxLength(60)
                                    ->required(),

                                TextInput::make('value')
                                    ->label('Nilai')
                                    ->maxLength(160)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(2)
                            ->maxItems(2)
                            ->reorderable(false)
                            ->addActionLabel('Tambah fakta')
                            ->helperText('Maksimal dua. Baris pertama di kiri, kedua di kanan.'),
                    ])
                    ->columns(2),

                // ---------------------------------------------------------
                // 3. Lookbook split
                // ---------------------------------------------------------
                Section::make('Lookbook Split')
                    ->description('Dua panel editorial bersebelahan.')
                    ->schema([
                        Repeater::make('lookbook')
                            ->label('Panel lookbook')
                            ->schema([
                                TextInput::make('eyebrow')
                                    ->label('Label kecil')
                                    ->maxLength(160),

                                TextInput::make('title')
                                    ->label('Judul')
                                    ->maxLength(255),

                                Textarea::make('body')
                                    ->label('Isi')
                                    ->rows(3)
                                    ->maxLength(1000),

                                Select::make('image_path')
                                    ->label('Foto')
                                    ->options($gambar)
                                    ->searchable()
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(2)
                            ->maxItems(4)
                            ->addActionLabel('Tambah panel')
                            // Filament menyuntik nilai per NAMA parameter, jadi harus
                            // `$state`. Parameter bernama lain gagal di-resolve.
                            ->itemLabel(fn (array $state): string => $state['title'] ?? 'Panel baru'),
                    ])
                    ->columns(1),

                // ---------------------------------------------------------
                // 4. Brand manifesto
                // ---------------------------------------------------------
                Section::make('Brand Manifesto')
                    ->description('Panel manifesyo di atas latar gelap.')
                    ->schema([
                        Select::make('manifesto.image_path')
                            ->label('Gambar manifesyo')
                            ->options($gambar)
                            ->searchable(),

                        TextInput::make('manifesto.badge')
                            ->label('Badge pojok kiri atas')
                            ->maxLength(120),

                        TextInput::make('manifesto.eyebrow')
                            ->label('Label kecil')
                            ->maxLength(160),

                        TextInput::make('manifesto.title')
                            ->label('Judul')
                            ->maxLength(255),

                        Textarea::make('manifesto.body')
                            ->label('Manifesto')
                            ->rows(4)
                            ->maxLength(2000),

                        TextInput::make('manifesto.strip_left')
                            ->label('Teks strip kiri')
                            ->maxLength(120),

                        TextInput::make('manifesto.strip_right')
                            ->label('Teks strip kanan')
                            ->maxLength(120),

                        Repeater::make('manifesto.points')
                            ->label('Dua poin')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Judul poin')
                                    ->maxLength(160)
                                    ->required(),

                                Textarea::make('body')
                                    ->label('Keterangan')
                                    ->rows(2)
                                    ->maxLength(600)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(2)
                            ->maxItems(2)
                            ->reorderable(false)
                            ->addActionLabel('Tambah poin'),

                        TextInput::make('manifesto.cta_primary_label')
                            ->label('Tombol utama — teks')
                            ->maxLength(120),

                        TextInput::make('manifesto.cta_primary_url')
                            ->label('Tombol utama — tujuan')
                            ->maxLength(255),

                        TextInput::make('manifesto.cta_secondary_label')
                            ->label('Tombol kedua — teks')
                            ->maxLength(120),

                        TextInput::make('manifesto.cta_secondary_url')
                            ->label('Tombol kedua — tujuan')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                // ---------------------------------------------------------
                // 5. Header headwear & gear
                // ---------------------------------------------------------
                Section::make('Header Headwear & Gear')
                    ->description('Section ini hanya tampil bila ada produk di kategori aksesori. Kosongkan `Judul` untuk menyembunyikannya.')
                    ->schema([
                        TextInput::make('gear.eyebrow')
                            ->label('Label kecil')
                            ->maxLength(160),

                        TextInput::make('gear.title')
                            ->label('Judul')
                            ->maxLength(255),

                        TextInput::make('gear.cta_label')
                            ->label('Teks tombol')
                            ->maxLength(120)
                            ->helperText('Tautan ke katalog kategori aksesori.'),
                    ])
                    ->columns(3),

                // ---------------------------------------------------------
                // 6. Promo
                // ---------------------------------------------------------
                Section::make('Promo Musim')
                    ->description('Section disembunyikan frontend selama `Kode Promo` masih kosong. Jangan isi kode sebelum promo benar-benar disetujui.')
                    ->schema([
                        TextInput::make('promo.eyebrow')
                            ->label('Label kecil')
                            ->maxLength(160),

                        TextInput::make('promo.title')
                            ->label('Judul promo')
                            ->maxLength(255),

                        Textarea::make('promo.body')
                            ->label('Isi promo')
                            ->rows(3)
                            ->maxLength(1000),

                        Repeater::make('promo.badges')
                            ->label('Badge')
                            ->simple(TextInput::make('label')->maxLength(60))
                            ->addActionLabel('Tambah badge')
                            ->reorderable(false),

                        TextInput::make('promo.deadline_note')
                            ->label('Batas waktu & kuota')
                            ->maxLength(255),

                        TextInput::make('promo.code_label')
                            ->label('Label kode')
                            ->maxLength(120),

                        TextInput::make('promo.code')
                            ->label('Kode promo')
                            ->maxLength(60)
                            ->helperText('Kosongkan untuk menyembunyikan seluruh section promo dari beranda.'),

                        TextInput::make('promo.code_body')
                            ->label('Keterangan kode')
                            ->maxLength(255),

                        TextInput::make('promo.cta_primary_label')
                            ->label('Tombol utama — teks')
                            ->maxLength(120),

                        TextInput::make('promo.cta_secondary_label')
                            ->label('Tombol kedua — teks')
                            ->maxLength(120),

                        TextInput::make('promo.cta_secondary_url')
                            ->label('Tombol kedua — tujuan')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                // ---------------------------------------------------------
                // 7 & 8. Storefront & pesanan custom
                // ---------------------------------------------------------
                Section::make('Toko Fisik & Pesanan Custom')
                    ->description('Alamat toko dan jam buka TIDAK ada di sini — keduanya dibaca langsung dari menu Pengaturan, jadi tidak ada dua sumber untuk satu nilai.')
                    ->schema([
                        TextInput::make('store.eyebrow')
                            ->label('Kotak toko — label kecil')
                            ->maxLength(120),

                        TextInput::make('store.title')
                            ->label('Kotak toko — judul')
                            ->maxLength(255),

                        Textarea::make('store.body')
                            ->label('Kotak toko — isi')
                            ->rows(3)
                            ->maxLength(1000),

                        TextInput::make('store.maps_label')
                            ->label('Kotak toko — teks tombol Maps')
                            ->maxLength(120),

                        TextInput::make('bulk.eyebrow')
                            ->label('Kotak custom — label kecil')
                            ->maxLength(120),

                        TextInput::make('bulk.title')
                            ->label('Kotak custom — judul')
                            ->maxLength(255),

                        Textarea::make('bulk.body')
                            ->label('Kotak custom — isi')
                            ->rows(3)
                            ->maxLength(1000),

                        Repeater::make('bulk.points')
                            ->label('Kotak custom — poin layanan')
                            ->simple(TextInput::make('label')->maxLength(160))
                            ->addActionLabel('Tambah poin')
                            ->reorderable(false),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Simpan Konten Beranda')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action(function (): void {
                    $this->saveContent();
                }),
        ];
    }

    /**
     * Peta field form -> key `app_settings` yang dibaca API.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    private function sections(): array
    {
        return [
            'benefit' => ['home.benefit', ['title', 'body', 'voucher_code', 'voucher_note', 'cta_label']],
            'film' => ['home.film', ['video_path', 'poster_path', 'chapter', 'tag', 'caption', 'spec', 'eyebrow', 'title', 'body', 'cta_primary_label', 'cta_primary_url', 'facts']],
            'lookbook' => ['home.lookbook', []],
            'manifesto' => ['home.manifesto', ['image_path', 'badge', 'eyebrow', 'title', 'body', 'strip_left', 'strip_right', 'points', 'cta_primary_label', 'cta_primary_url', 'cta_secondary_label', 'cta_secondary_url']],
            'gear' => ['home.gear', ['eyebrow', 'title', 'cta_label']],
            'promo' => ['home.promo', ['eyebrow', 'badges', 'title', 'body', 'deadline_note', 'code_label', 'code', 'code_body', 'cta_primary_label', 'cta_secondary_label', 'cta_secondary_url']],
            'store' => ['home.store', ['eyebrow', 'title', 'body', 'maps_label']],
            'bulk' => ['home.bulk', ['eyebrow', 'title', 'body', 'points', 'cta_label']],
        ];
    }

    /**
     * Simpan seluruh section.
     *
     * Satu transaksi untuk kedelapan: pengunjung tidak boleh sempat
     * melihat beranda dengan manifesto versi baru tapi lookbook versi lama.
     */
    public function saveContent(): void
    {
        $validated = $this->form->getState();

        DB::transaction(function () use ($validated): void {
            foreach ($this->sections() as $field => [$key, $subKeys]) {
                $value = $validated[$field] ?? [];

                if (! is_array($value)) {
                    $value = [];
                }

                // Daftar (`lookbook`, `points`, `facts`, `badges`) disimpan
                // utuh. Objek biasa hanya mengambil sub-key yang ada di form,
                // supaya field tak dikenal yang tersimpan di DB tidak ikut
                // ditulis ulang.
                $bersih = $subKeys === []
                    ? array_values($value)
                    : array_intersect_key($value, array_flip($subKeys));

                AppSetting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => ['value' => $bersih],
                        'group' => 'homepage',
                    ],
                );
            }
        });

        Notification::make()
            ->success()
            ->title('Konten beranda disimpan')
            ->body('Delapan section tersimpan. Beranda langsung menampilkan perubahan tanpa deploy.')
            ->send();
    }

    /**
     * Nilai satu key, dijamin array.
     *
     * @return array<string, mixed>|list<mixed>
     */
    private function raw(string $key): array
    {
        $value = AppSetting::get($key, []);

        return is_array($value) ? $value : [];
    }
}
