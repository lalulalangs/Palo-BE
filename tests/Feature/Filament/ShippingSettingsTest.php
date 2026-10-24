<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageShippingSettings;
use App\Models\AdminUser;
use App\Models\AppSetting;
use App\Services\Shipping\ShippingRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

class ShippingSettingsTest extends TestCase
{
    use FakesRajaOngkir;
    use RefreshDatabase;

    protected AdminUser $admin;

    protected function setUp(): void
    {
        // Fake RajaOngkir dipasang lewat setUpTraits().
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);
    }

    public function test_authenticated_admin_can_render_shipping_settings_page(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ManageShippingSettings::class)
            ->assertSuccessful()
            // Admin hanya melihat nama gudang dan pilihan kurir, bukan ID/kode teknis.
            ->assertFormFieldExists('shipping_origin_subdistrict_id')
            ->assertFormFieldExists('shipping_enabled_couriers')
            ->assertFormFieldExists('hide_cargo_services');
    }

    public function test_can_save_origin_and_couriers(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ManageShippingSettings::class)
            ->fillForm([
                'shipping_origin_subdistrict_id' => 746,
                'shipping_origin_label' => 'BAYAN, BAYAN, LOMBOK UTARA, NUSA TENGGARA BARAT (NTB), 83354',
                'shipping_enabled_couriers' => ['jne', 'jnt'],
                'hide_cargo_services' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('746', AppSetting::getValue('shipping_origin_subdistrict_id'));
        $this->assertSame(
            'BAYAN, BAYAN, LOMBOK UTARA, NUSA TENGGARA BARAT (NTB), 83354',
            AppSetting::getValue('shipping_origin_label'),
        );
        // Disimpan dalam format yang diterima API kurir.
        $this->assertSame('jne:jnt', AppSetting::getValue('shipping_enabled_couriers'));
        $this->assertSame('JTR,JTR<130,JTR>130,JTR>200', AppSetting::getValue('shipping_excluded_services'));
    }

    public function test_cargo_services_can_be_shown_again(): void
    {
        AppSetting::setValue('shipping_excluded_services', 'JTR,JTR<130,JTR>130,JTR>200');

        Livewire::actingAs($this->admin, 'admin')
            ->test(ManageShippingSettings::class)
            ->fillForm([
                'shipping_origin_subdistrict_id' => 746,
                'shipping_origin_label' => 'Bayan, Lombok Utara',
                'shipping_enabled_couriers' => ['jne'],
                'hide_cargo_services' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(AppSetting::getValue('shipping_excluded_services'));
    }

    public function test_destination_label_is_readable_for_origin_picker(): void
    {
        // Bentuk mentah dari API: huruf kapital semua + provinsi panjang.
        $this->assertSame(
            'Bayan, Lombok Utara, NTB, 83354',
            ShippingRateService::readableDestinationLabel([
                'subdistrict_name' => 'BAYAN',
                'city_name' => 'LOMBOK UTARA',
                'province_name' => 'NUSA TENGGARA BARAT (NTB)',
                'zip_code' => '83354',
            ]),
        );

        // Provinsi tanpa singkatan dalam kurung tetap wajar, akronim tidak rusak.
        $this->assertSame(
            'Cempaka Putih Barat, Jakarta Pusat, DKI Jakarta, 10520',
            ShippingRateService::readableDestinationLabel([
                'subdistrict_name' => 'CEMPAKA PUTIH BARAT',
                'city_name' => 'JAKARTA PUSAT',
                'province_name' => 'DKI JAKARTA',
                'zip_code' => '10520',
            ]),
        );
    }

    public function test_form_rejects_empty_courier_selection(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ManageShippingSettings::class)
            ->fillForm([
                'shipping_origin_subdistrict_id' => 746,
                'shipping_enabled_couriers' => [],
            ])
            ->call('save')
            ->assertHasFormErrors(['shipping_enabled_couriers']);
    }
}
