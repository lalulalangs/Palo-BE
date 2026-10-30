<?php

namespace App\Filament\Pages;

use App\Enums\AdminFeature;
use App\Filament\Concerns\HasAdminFeatureAccess;
use App\Filament\Widgets\ShippingQuotaWidget;
use App\Models\AppSetting;
use App\Services\Shipping\ShippingRateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * Konfigurasi ekspedisi ada di `app_settings`, bukan di kode: tarif harus
 * mengikuti gudang dan kurir yang benar-benar dipakai, dan nilai tebakan di
 * kode pernah membuat semua tarif salah tanpa error apa pun.
 *
 * Halaman ini sengaja tidak menampilkan ID atau kode teknis RajaOngkir ke
 * admin: gudang dipilih lewat pencarian nama, dan layanan kargo hanya berupa
 * satu tombol nyala/mati.
 */
class ManageShippingSettings extends Page
{
    use HasAdminFeatureAccess;
    use InteractsWithFormActions;

    /**
     * Kode layanan kargo JNE yang disembunyikan dari pembeli. Tarifnya mulai
     * ratusan ribu sampai jutaan rupiah sehingga tidak masuk akal ditampilkan
     * di checkout.
     */
    private const CARGO_SERVICE_CODES = 'JTR,JTR<130,JTR>130,JTR>200';

    /**
     * Kode kurir domestik RajaOngkir V2 beserta nama tampilannya.
     *
     * @var array<string, string>
     */
    private const COURIER_OPTIONS = [
        'jne' => 'JNE',
        'jnt' => 'J&T',
        'sicepat' => 'SiCepat',
        'anteraja' => 'AnterAja',
        'ninja' => 'Ninja Xpress',
        'lion' => 'Lion Parcel',
        'pos' => 'POS Indonesia',
        'tiki' => 'TIKI',
        'ide' => 'IDExpress',
        'sap' => 'SAP Express',
        'ncs' => 'NCS',
        'rex' => 'REX',
        'rpx' => 'RPX',
        'sentral' => 'Sentral Cargo',
        'star' => 'Star Cargo',
        'wahana' => 'Wahana',
        'dse' => 'DSE',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Ekspedisi & Ongkir';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Pengaturan Ekspedisi & Ongkir';

    protected static ?string $slug = 'pengaturan-ekspedisi';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    /**
     * Penampung hasil pencarian gudang: id subdistrict => label.
     *
     * @var array<int, string>
     */
    public array $originOptions = [];

    public static function getAdminFeature(): AdminFeature
    {
        return AdminFeature::ShippingSettings;
    }

    /**
     * @return array<class-string<\Filament\Widgets\Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            ShippingQuotaWidget::class,
        ];
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $settings = AppSetting::getMany([
            'shipping_origin_subdistrict_id' => null,
            'shipping_origin_label' => null,
            'shipping_enabled_couriers' => 'jne:jnt',
            'shipping_excluded_services' => self::CARGO_SERVICE_CODES,
        ]);

        $couriers = array_values(array_filter(explode(':', (string) $settings['shipping_enabled_couriers'])));

        $this->form->fill([
            'shipping_origin_subdistrict_id' => $settings['shipping_origin_subdistrict_id'],
            'shipping_origin_label' => $settings['shipping_origin_label'],
            'shipping_enabled_couriers' => $couriers,
            'hide_cargo_services' => filled($settings['shipping_excluded_services']),
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Gudang Asal')
                    ->description('Titik kirim yang dipakai untuk semua perhitungan ongkir. Cukup ketik nama kecamatan/desa, lalu pilih dari daftar.')
                    ->schema([
                        Select::make('shipping_origin_subdistrict_id')
                            ->label('Gudang Asal')
                            ->searchable()
                            ->required()
                            ->placeholder('Ketik nama kecamatan atau desa, mis. Bayan')
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchOriginOptions($search))
                            ->getOptionLabelUsing(function (mixed $value, Get $get): ?string {
                                if (blank($value)) {
                                    return null;
                                }

                                return $this->originOptions[(int) $value]
                                    ?? $get('shipping_origin_label')
                                    ?? AppSetting::getValue('shipping_origin_label')
                                    ?? (string) $value;
                            })
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                // Label disimpan sekali saat dipilih supaya
                                // halaman tidak perlu memanggil API lagi.
                                $set('shipping_origin_label', $this->originOptions[(int) $state] ?? null);
                            })
                            ->live()
                            ->helperText('Ini menentukan tarif dihitung dari mana. Salah gudang berarti semua tarif salah, jadi pastikan pilihannya benar.'),
                        Hidden::make('shipping_origin_label'),
                    ]),

                Section::make('Kurir Aktif')
                    ->description('Pilih kurir yang mau ditawarkan. Kurir yang tidak melayani rute tertentu otomatis tidak muncul di checkout — jadi wajar bila sebuah kurir dicentang tetapi tidak terlihat untuk sebagian tujuan.')
                    ->schema([
                        CheckboxList::make('shipping_enabled_couriers')
                            ->label('Kurir')
                            ->options(self::COURIER_OPTIONS)
                            ->columns(3)
                            ->required()
                            ->minItems(1)
                            ->validationMessages([
                                'required' => 'Minimal satu kurir harus dipilih.',
                            ])
                            ->helperText('Dari gudang Bayan, kurir yang terukur punya layanan adalah JNE, J&T, Lion, dan POS. Kurir lain tetap boleh dicentang, tetapi tidak akan muncul selama tidak punya layanan dari Lombok Utara.'),
                    ]),

                Section::make('Layanan Kargo')
                    ->description('JNE memiliki layanan kargo (JTR) bertarif ratusan ribu sampai jutaan rupiah. Layanan itu disembunyikan agar pembeli tidak melihat harga yang tidak masuk akal.')
                    ->schema([
                        Toggle::make('hide_cargo_services')
                            ->label('Sembunyikan layanan kargo')
                            ->default(true)
                            ->helperText('Biarkan aktif. Matikan hanya bila suatu saat ingin menampilkan layanan kargo ke pembeli.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    EmbeddedSchema::make('form'),
                ])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Perubahan')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $normalize = fn (mixed $value): ?string => blank($value) ? null : trim((string) $value);

        $originId = $state['shipping_origin_subdistrict_id'] ?? null;
        $originLabel = $state['shipping_origin_label']
            ?? ($this->originOptions[(int) $originId] ?? null)
            ?? AppSetting::getValue('shipping_origin_label');

        AppSetting::setMany([
            'shipping_origin_subdistrict_id' => $normalize($originId),
            'shipping_origin_label' => $normalize($originLabel),
            'shipping_enabled_couriers' => implode(':', (array) ($state['shipping_enabled_couriers'] ?? [])),
            'shipping_excluded_services' => ($state['hide_cargo_services'] ?? true)
                ? self::CARGO_SERVICE_CODES
                : null,
        ]);

        Notification::make()
            ->title('Pengaturan ekspedisi berhasil disimpan')
            ->success()
            ->send();
    }

    /**
     * Opsi pencarian gudang. Satu panggilan per ketikan, hasilnya di-cache
     * ShippingRateService (7 hari per kata kunci) agar kuota tidak jebol.
     *
     * @return array<int, string>
     */
    protected function searchOriginOptions(string $search): array
    {
        $search = trim($search);

        // Hindari memanggil API untuk ketikan yang terlalu pendek.
        if (mb_strlen($search) < 3) {
            return [];
        }

        try {
            $destinations = app(ShippingRateService::class)->searchDestinations($search, 10);
        } catch (Throwable) {
            Notification::make()
                ->title('Gagal mencari gudang')
                ->body('Layanan ongkir sedang tidak dapat dihubungi. Coba lagi beberapa saat lagi.')
                ->danger()
                ->send();

            return [];
        }

        $options = [];

        foreach ($destinations as $destination) {
            // Tampilkan "Bayan, Lombok Utara, NTB, 83354", bukan label mentah API.
            $options[$destination['id']] = ShippingRateService::readableDestinationLabel($destination);
        }

        $this->originOptions += $options;

        return $options;
    }
}
