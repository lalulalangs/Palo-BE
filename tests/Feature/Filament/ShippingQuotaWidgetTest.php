<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\ShippingQuotaWidget;
use App\Models\AdminUser;
use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShippingQuotaWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.api_key' => null,
            'shipping.daily_hit_quota' => 100,
        ]);

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);
    }

    private function setHits(int $count): void
    {
        AppSetting::setValue('shipping_hits_today', json_encode([
            'date' => now()->toDateString(),
            'count' => $count,
        ]));
    }

    private function configureShipping(): void
    {
        config(['shipping.api_key' => 'test-api-key']);
        AppSetting::setValue('shipping_origin_subdistrict_id', '746');
        AppSetting::setValue('shipping_enabled_couriers', 'jne:jnt');
    }

    public function test_widget_shows_today_quota_usage(): void
    {
        $this->setHits(87);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ShippingQuotaWidget::class)
            ->assertSuccessful()
            ->assertSee('Kuota Ongkir Hari Ini')
            ->assertSee('87 / 100 HIT')
            ->assertSee('13')
            ->assertSee('Kuota hampir habis', escape: false);
    }

    public function test_widget_is_registered_on_shipping_settings_page_and_not_on_admin_dashboard(): void
    {
        // ShippingQuotaWidget tidak lagi muncul di Dashboard /admin
        $this->actingAs($this->admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('ShippingQuotaWidget');

        // ShippingQuotaWidget kini terdaftar sebagai header widget di halaman pengaturan ekspedisi
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/pengaturan-ekspedisi')
            ->assertOk()
            ->assertSee('ShippingQuotaWidget');
    }

    public function test_widget_warns_when_configuration_is_incomplete(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ShippingQuotaWidget::class)
            ->assertSuccessful()
            ->assertSee('Belum lengkap')
            ->assertSee('API key RajaOngkir, gudang asal, kurir aktif');
    }

    public function test_widget_reports_ready_when_configuration_is_complete(): void
    {
        $this->configureShipping();

        Livewire::actingAs($this->admin, 'admin')
            ->test(ShippingQuotaWidget::class)
            ->assertSuccessful()
            ->assertSee('Siap')
            ->assertSee('Checkout online bisa menghitung ongkir.');
    }

    public function test_missing_configuration_lists_each_absent_setting(): void
    {
        $widget = new ShippingQuotaWidget;

        $this->assertSame(
            ['API key RajaOngkir', 'gudang asal', 'kurir aktif'],
            $widget->missingConfiguration(),
        );

        $this->configureShipping();

        $this->assertSame([], $widget->missingConfiguration());
    }
}
