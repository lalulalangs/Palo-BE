<?php

namespace App\Filament\Resources\AppSettings;

use App\Domain\Shared\Models\AppSetting;
use App\Filament\Resources\AppSettings\Pages\EditAppSetting;
use App\Filament\Resources\AppSettings\Pages\ListAppSettings;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pengaturan toko dan WhatsApp.
 *
 * ===================================================================
 *  NOMOR WHATSAPP CS DI SINI, BUKAN DI FORM TERPISAH
 * ===================================================================
 * PRD §3.11: "Nomor WhatsApp CS dikonfigurasi dari Admin panel, bukan
 * hardcode." AC: "Given Admin mengganti nomor WhatsApp CS, When guest melakukan
 * checkout, Then link mengarah ke nomor baru tanpa perlu redeploy aplikasi."
 *
 * Aplikasi membacanya lewat `AppSetting::get('whatsapp.cs_number')` (lihat
 * `Guest\Actions\CreateGuestCheckoutIntent`). Karena itu tidak boleh ada form
 * "nomor CS" terpisah di tempat lain: dua sumber untuk satu nilai pasti akan
 * menyimpang, dan nomor yang benar-benar dipakai checkout justru yang hilang
 * dari pandangan.
 *
 * `AppSetting::requiredKeys()` mendaftarkan kunci yang wajib terisi sebelum
 * toko beroperasi; command `palorinjani:check-settings` memperingatkan kalau
 * ada yang belum diisi atau masih berupa placeholder.
 */
class AppSettingResource extends Resource
{
    protected static ?string $model = AppSetting::class;

    protected static ?string $recordTitleAttribute = 'key';

    protected static ?string $modelLabel = 'Pengaturan';

    protected static ?string $pluralModelLabel = 'Pengaturan';

    protected static ?string $navigationLabel = 'Toko & WhatsApp';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'pengaturan';

    /**
     * Definisi kunci yang dikelola lewat panel.
     *
     * Tiap entri memberi tahu tipe input yang tepat, apakah wajib, dan contoh
     * format. Tanpa ini, admin akan mengisi nomor WhatsApp dengan format apa
     * saja dan tautan `wa.me` nanti gagal.
     *
     * @return array<string, array{label: string, group: string, type: string, required: bool, hint: string}>
     */
    public static function settingDefinitions(): array
    {
        return [
            'store.name' => [
                'label' => 'Nama Toko',
                'group' => 'store',
                'type' => 'text',
                'required' => true,
                'hint' => 'Nama yang tampil di header storefront, email, dan pesan WhatsApp.',
            ],
            'store.address' => [
                'label' => 'Alamat Toko',
                'group' => 'store',
                'type' => 'textarea',
                'required' => true,
                'hint' => 'Alamat lengkap. Muncul di halaman "Toko dan Kontak".',
            ],
            'store.city' => [
                'label' => 'Kota',
                'group' => 'store',
                'type' => 'text',
                'required' => true,
                'hint' => 'Kota tempat toko berada.',
            ],
            'store.phone' => [
                'label' => 'Telepon Toko',
                'group' => 'store',
                'type' => 'text',
                'required' => true,
                'hint' => 'Nomor telepon yang bisa dihubungi buyer.',
            ],
            'whatsapp.cs_number' => [
                'label' => 'Nomor WhatsApp CS',
                'group' => 'whatsapp',
                'type' => 'text',
                'required' => true,
                'hint' => 'Format internasional tanpa tanda plus, contoh 6281234567890. Tautan wa.me memakai nomor ini. Mengubahnya langsung berlaku tanpa deploy.',
            ],
            'whatsapp.cs_message_template' => [
                'label' => 'Template Pesan CS',
                'group' => 'whatsapp',
                'type' => 'textarea',
                'required' => true,
                'hint' => 'Template pesan pembuka. Pakai placeholder agar isi pesan tidak di-hardcode di dalam kode.',
            ],
            'shipping.origin_city' => [
                'label' => 'Kota Asal Pengiriman',
                'group' => 'shipping',
                'type' => 'text',
                'required' => true,
                'hint' => 'PRD §3.8: ongkir dihitung dari kota asal. Nilai ini harus disetujui pemilik, bukan ditebak.',
            ],
            'shipping.origin_city_code' => [
                'label' => 'Kode Kota Asal',
                'group' => 'shipping',
                'type' => 'text',
                'required' => true,
                'hint' => 'Kode kota sesuai format kurir yang dipakai, umumnya empat digit.',
            ],

            // -----------------------------------------------------------------
            // KANAL MEDIA SOSIAL
            // -----------------------------------------------------------------
            // `required: false` untuk semua. Footer menyembunyikan ikon yang
            // belum diisi, jadi channel yang belum dipakai tidak perlu diisi
            // hanya agar pengaturan terasa "lengkap".
            //
            // Untuk WhatsApp TIDAK ada key URL terpisah: footer memakai
            // `whatsapp.cs_number` lalu merangkai `wa.me`. Dua key untuk satu
            // nilai pasti akan menyimpang — nomor yang dipakai checkout bisa
            // berbeda dari yang ditampilkan di footer.
            'brand.instagram_url' => [
                'label' => 'URL Instagram',
                'group' => 'social',
                'type' => 'text',
                'required' => false,
                'hint' => 'Tautan lengkap, contoh https://instagram.com/namaakun. Ikon Instagram di footer disembunyikan selama kosong.',
            ],
            'brand.facebook_url' => [
                'label' => 'URL Facebook',
                'group' => 'social',
                'type' => 'text',
                'required' => false,
                'hint' => 'Tautan halaman resmi, contoh https://facebook.com/namahalaman.',
            ],
            'brand.tiktok_url' => [
                'label' => 'URL TikTok',
                'group' => 'social',
                'type' => 'text',
                'required' => false,
                'hint' => 'Tautan profil, contoh https://tiktok.com/@namaakun.',
            ],
        ];
    }

    /**
     * @return Builder<AppSetting>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Nilai Pengaturan')
                ->description('Semua nilai di sini dibaca aplikasi secara langsung. Tidak ada nilai yang di-hardcode di kode.')
                ->schema([
                    // `key` sengaja read-only: ia adalah alamat rujukan yang
                    // dipakai `AppSetting::get()` di seluruh domain. Mengubah
                    // kunci berarti membuat semua pemanggilan itu diam-diam
                    // jatuh ke nilai default.
                    TextInput::make('key')
                        ->label('Kunci')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Kunci tidak bisa diubah karena dibaca langsung oleh aplikasi.'),

                    TextInput::make('label')
                        ->label('Label Tampilan')
                        ->maxLength(255)
                        ->helperText('Nama field di panel ini.'),

                    // Kolom `value` di database adalah JSON. Bentuk inputnya
                    // berbeda per kunci, jadi dipakai dua field terpisah yang
                    // salah satunya disembunyikan. Pemetaan ke kolom `value`
                    // dilakukan di `EditAppSetting`.
                    TextInput::make('value_text')
                        ->label('Nilai')
                        ->visible(fn (AppSetting $record): bool => self::inputType($record->key) === 'text')
                        ->required(fn (AppSetting $record): bool => self::isRequired($record->key))
                        ->dehydrated(fn (AppSetting $record): bool => self::inputType($record->key) === 'text')
                        ->helperText(fn (AppSetting $record): string => self::hintFor($record->key))
                        ->maxLength(1000),

                    Textarea::make('value_textarea')
                        ->label('Nilai')
                        ->rows(4)
                        ->visible(fn (AppSetting $record): bool => self::inputType($record->key) === 'textarea')
                        ->required(fn (AppSetting $record): bool => self::isRequired($record->key))
                        ->dehydrated(fn (AppSetting $record): bool => self::inputType($record->key) === 'textarea')
                        ->helperText(fn (AppSetting $record): string => self::hintFor($record->key))
                        ->maxLength(5000),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('group')
            ->columns([
                TextColumn::make('key')
                    ->label('Kunci')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->description(fn (AppSetting $record): string => self::hintFor($record->key)),

                TextColumn::make('value')
                    ->label('Nilai')
                    ->formatStateUsing(fn (AppSetting $record): string => self::displayValue($record))
                    ->color(fn (AppSetting $record): string => self::displayValue($record) === 'Belum diisi' ? 'danger' : 'gray')
                    ->wrap(),

                TextColumn::make('group')
                    ->label('Grup')
                    ->badge()
                    ->sortable(),

                TextColumn::make('label')
                    ->label('Label')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label('Grup')
                    ->options([
                        'store' => 'Toko',
                        'whatsapp' => 'WhatsApp',
                        'shipping' => 'Pengiriman',
                        'social' => 'Media Sosial',
                        'general' => 'Umum',
                    ]),
            ])
            ->recordActions([
                // Hanya `EditAction`. `CreateAction` dan `DeleteAction`
                // sengaja tidak dipasang karena `AppSettingPolicy` menolak
                // keduanya: kunci baru harus dibuat dengan sengaja, dan kunci
                // lama harus tetap terbaca oleh `AppSetting::get()`.
                EditAction::make()
                    ->label('Ubah'),
            ])
            ->emptyStateHeading('Belum ada pengaturan')
            ->emptyStateDescription('Isi lewat panel ini. Jalankan `php artisan palorinjani:check-settings` untuk melihat daftar kunci yang masih wajib diisi.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAppSettings::route('/'),
            'edit' => EditAppSetting::route('/{record}/edit'),
        ];
    }

    /**
     * Tipe input yang tepat untuk sebuah kunci.
     */
    public static function inputType(string $key): string
    {
        return self::settingDefinitions()[$key]['type'] ?? 'text';
    }

    public static function isRequired(string $key): bool
    {
        return (bool) (self::settingDefinitions()[$key]['required'] ?? false);
    }

    public static function hintFor(string $key): string
    {
        return self::settingDefinitions()[$key]['hint'] ?? '';
    }

    public static function labelFor(string $key): string
    {
        return self::settingDefinitions()[$key]['label'] ?? $key;
    }

    /**
     * Nilai setting dalam bentuk yang enak dibaca.
     *
     * Kolom `value` adalah JSON dengan bentuk `['value' => ...]`, jadi harus
     * dibuka dulu. Menampilkan JSON mentah akan membingungkan admin.
     */
    public static function displayValue(AppSetting $record): string
    {
        $raw = $record->value['value'] ?? null;

        if ($raw === null) {
            return 'Belum diisi';
        }

        if (is_bool($raw)) {
            return $raw ? 'Ya' : 'Tidak';
        }

        if (is_scalar($raw)) {
            return (string) $raw;
        }

        return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '-';
    }
}
