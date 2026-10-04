<?php

namespace App\Filament\Pages;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Domain\Shared\Models\AppSetting;
use App\Filament\Resources\AppSettings\AppSettingResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tautan media sosial untuk footer.
 *
 * ===================================================================
 *  KENAPA ADA HALAMAN INI, BUKAN CUMA BARIS DI TABEL `app_settings`
 * ===================================================================
 * Semua key media sosial sebenarnya sudah bisa diedit dari
 * `AppSettingResource`. Tapi di sana tiap key adalah baris terpisah yang
 * harus dibuka satu per satu: empat tautan berarti empat kunjungan ke halaman
 * edit, dan tidak ada yang menunjukkan bahwa keempatnya membentuk satu
 * kelompok.
 *
 * Halaman ini menyatukan keempat kanal dalam satu form, sehingga:
 *   - Admin melihat semua kanal sekaligus, termasuk mana yang masih kosong.
 *   - Satu klik simpan untuk semuanya, jadi tidak mungkin ada keadaan
 *     "Instagram sudah diganti tapi Facebook masih yang lama".
 *
 * ===================================================================
 *  SATU SUMBER KEBENARAN
 * ===================================================================
 * Field form di sini BUKAN key baru. `statePath` memakai nama key aslinya
 * (`instagram_url`, `facebook_url`, `tiktok_url`, `whatsapp_number`) dan
 * `saveSocial()` memanggil `AppSetting::put()` dengan key yang sama seperti
 * yang dibaca domain.
 *
 * Khusus WhatsApp, form ini menulis `whatsapp.cs_number` — key yang juga
 * dipakai checkout guest (PRD §3.11). Itu disengaja: nomor WhatsApp untuk
 * checkout dan untuk footer PASTI harus sama. Kalau dikasih key terpisah
 * (`social.whatsapp_url`), keduanya akan menyimpang diam-diam, dan yang
 * dipakai pembeli bisa bukan yang tertulis di footer.
 *
 * Frontend merangkai tautan `wa.me` sendiri dari nomor itu, jadi tidak ada
 * URL WhatsApp yang bisa diisi keliru.
 */
class SocialMediaSettings extends Page
{
    protected static ?string $navigationLabel = 'Media Sosial';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-share';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Media Sosial';

    protected static ?string $slug = 'media-sosial';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Sama aturan dengan `AppSettingPolicy::update()`: menulis kanal publik
     * brand adalah keputusan brand, jadi khusus Superadmin.
     */
    public static function canAccess(): bool
    {
        return Gate::check('updateAny', [AppSetting::class]);
    }

    public function mount(): void
    {
        $this->form->fill([
            'instagram_url' => AppSetting::get('brand.instagram_url'),
            'facebook_url' => AppSetting::get('brand.facebook_url'),
            'tiktok_url' => AppSetting::get('brand.tiktok_url'),
            'whatsapp_number' => AppSetting::get('whatsapp.cs_number'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        /*
         * `getSchema('form')`, BUKAN `$this->form`.
         *
         * `$this->form` memicu `__get()`, dan `__get()` Filament hanya
         * menjawab saat Filament TIDAK sedang menyusun schema. Padahal
         * `content()` sendiri sedang disusun oleh `cacheSchema('content')`,
         * jadi `isCachingSchemas()` bernilai `true` dan `__get()` melempar
         * `PropertyNotFoundException` — halaman jadi 500.
         *
         * Memanggil `getSchema()` sebagai method biasa aman: ia tidak lewat
         * `__get()`, dan `cacheSchema()`Ht mengaktifkan try/finally sendiri
         * sehingga penanda `isCachingSchemas` selalu kembali benar.
         */
        return $schema->components([$this->getSchema('form')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Tautan Media Sosial')
                    ->description('Tautan ini tampil sebagai ikon putih di footer storefront. Kanal yang dikosongkan akan disembunyikan, bukan ditampilkan sebagai ikon mati.')
                    ->schema([
                        Grid::make(2)
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('instagram_url')
                                    ->label('Instagram')
                                    ->url()
                                    ->placeholder('https://instagram.com/namaakun')
                                    ->maxLength(255)
                                    ->helperText('Halaman profil resmi brand.'),

                                TextInput::make('facebook_url')
                                    ->label('Facebook')
                                    ->url()
                                    ->placeholder('https://facebook.com/namahalaman')
                                    ->maxLength(255)
                                    ->helperText('Halaman resmi. Kosongkan kalau tidak dipakai.'),

                                TextInput::make('tiktok_url')
                                    ->label('TikTok')
                                    ->url()
                                    ->placeholder('https://tiktok.com/@namaakun')
                                    ->maxLength(255)
                                    ->helperText('Profil resmi. Kosongkan kalau tidak dipakai.'),

                                TextInput::make('whatsapp_number')
                                    ->label('WhatsApp')
                                    ->tel()
                                    ->placeholder('6281234567890')
                                    ->maxLength(20)
                                    // Format dijaga di sini, bukan hanya oleh
                                    // frontend: `whatsapp.cs_number` juga dibaca
                                    // checkout guest, jadi dua-digit yang salah
                                    // akan mengarahkan pembeli ke nomor salah.
                                    ->helperText('Format internasional tanpa "+" dan tanpa spasi, contoh 6281234567890. Tautan wa.me dirangkai otomatis dari nomor ini, jadi tidak ada kolom URL.'),
                            ]),
                    ])
                    ->columns(1),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Simpan Tautan')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action(function (): void {
                    $this->saveSocial();
                }),
        ];
    }

    /**
     * Peta field form -> key yang benar-benar dibaca aplikasi.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function targets(): array
    {
        return [
            'instagram_url' => [
                'brand.instagram_url',
                AppSettingResource::labelFor('brand.instagram_url'),
            ],
            'facebook_url' => [
                'brand.facebook_url',
                AppSettingResource::labelFor('brand.facebook_url'),
            ],
            'tiktok_url' => [
                'brand.tiktok_url',
                AppSettingResource::labelFor('brand.tiktok_url'),
            ],
            'whatsapp_number' => [
                'whatsapp.cs_number',
                AppSettingResource::labelFor('whatsapp.cs_number'),
            ],
        ];
    }

    /**
     * Simpan keempat tautan.
     *
     * Nilai kosong disimpan sebagai `null`, bukan string kosong. Frontend
     * membedakan keduanya, dan `""` akan lolos ke `readString()` sehingga
     * ikon tetap dirender dengan `href=""` — tautan yang memuat ulang halaman
     * toko sendiri saat diklik.
     */
    public function saveSocial(): void
    {
        $validated = $this->form->getState();
        $targets = $this->targets();

        $sebelum = [];
        $sesudah = [];

        // Satu transaksi untuk keempatnya: pengunjung tidak boleh melihat
        // Instagram versi baru berpasangan dengan Facebook versi lama.
        DB::transaction(function () use ($targets, $validated, &$sebelum, &$sesudah): void {
            foreach ($targets as $field => [$key, $label]) {
                $mentah = $validated[$field] ?? null;
                $nilai = is_string($mentah) ? trim($mentah) : $mentah;
                $nilai = ($nilai === null || $nilai === '') ? null : $nilai;

                // Nomor WhatsApp dibersihkan dari spasi, tanda hubung, dan
                // tanda plus. Admin akan mengetik "0812-3456-7890", dan
                // `wa.me` tidak menerima format itu.
                if ($key === 'whatsapp.cs_number' && $nilai !== null) {
                    $nilai = self::normalisasiNomorWa($nilai);
                }

                $lama = AppSetting::get($key);
                $sebelum[$key] = $lama;
                $sesudah[$key] = $nilai;

                if ($lama === $nilai) {
                    // Tidak berubah: jangan tulis ulang baris, supaya
                    // `updated_at` tidak berbohong tentang waktu perubahan.
                    continue;
                }

                $row = AppSetting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => ['value' => $nilai],
                        'group' => $key === 'whatsapp.cs_number' ? 'whatsapp' : 'social',
                        'label' => $label,
                    ],
                );

                // Nomor WhatsApp dipakai checkout, jadi perubahannya
                // meninggalkan jejak audit tersendiri.
                app(LogAdminActivity::class)->custom(
                    'social_media_update',
                    $row,
                    ['value' => $lama],
                    ['value' => $nilai],
                );
            }
        });

        $berubah = [];
        foreach ($targets as $field => [$key, $label]) {
            if (($sebelum[$key] ?? null) !== ($sesudah[$key] ?? null)) {
                $berubah[] = $label;
            }
        }

        Notification::make()
            ->success()
            ->title('Tautan media sosial disimpan')
            ->body(
                $berubah === []
                    ? 'Tidak ada perubahan dari nilai sebelumnya.'
                    : 'Diperbarui: '.implode(', ', $berubah).'.'
            )
            ->send();
    }

    /**
     * Bersihkan nomor WhatsApp menjadi bentuk yang diterima `wa.me`.
     *
     * Dipisah dari penyimpanan supaya aturannya bisa diuji langsung.
     */
    public static function normalisasiNomorWa(string $nomor): string
    {
        $bersih = preg_replace('/[^0-9+]/', '', $nomor) ?? $nomor;
        $bersih = ltrim($bersih, '+');

        // Nomor Indonesia yang ditulis lokal (08...) jadi 62...; kalau sudah
        // diawali 62 atau kode negara lain, biarkan apa adanya. Kita tidak
        // boleh menebak negara dari nomor yang tidak jelas.
        if (str_starts_with($bersih, '0')) {
            $bersih = '62'.substr($bersih, 1);
        }

        return $bersih;
    }
}
