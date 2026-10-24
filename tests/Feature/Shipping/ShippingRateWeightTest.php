<?php

namespace Tests\Feature\Shipping;

use App\Models\AppSetting;
use App\Services\Shipping\ShippingRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCatalogFixtures;
use Tests\TestCase;

/**
 * Berat & pemetaan layanan. Semua HTTP di-fake: API RajaOngkir tidak punya
 * sandbox sehingga panggilan nyata memotong kuota harian.
 */
class ShippingRateWeightTest extends TestCase
{
    use CreatesCatalogFixtures;
    use RefreshDatabase;

    private ShippingRateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.base_url' => 'https://rajaongkir.komerce.id/api/v1',
            'shipping.api_key' => 'test-api-key',
        ]);

        AppSetting::setValue('shipping_origin_subdistrict_id', '746');
        AppSetting::setValue('shipping_origin_label', 'Bayan, Lombok Utara, NTB, 83354');
        AppSetting::setValue('shipping_enabled_couriers', 'jne:jnt');
        AppSetting::setValue('shipping_excluded_services', 'JTR,JTR<130,JTR>130,JTR>200');

        $this->service = app(ShippingRateService::class);
    }

    public function test_weight_is_sent_in_grams_without_rounding_to_kilograms(): void
    {
        $sku = $this->createShippingSku(1200);

        $this->fakeCostResponse();

        $this->service->getQuotes('', [['sku_id' => $sku->id, 'quantity' => 1]], 17596);

        // 1.200 gram dikirim apa adanya; dulu dibulatkan menjadi 2.000 gram.
        Http::assertSent(fn (ClientRequest $request): bool => $request['weight'] == 1200);
    }

    public function test_quantity_multiplies_weight(): void
    {
        $sku = $this->createShippingSku(1000);

        $this->fakeCostResponse();

        $this->service->getQuotes('', [['sku_id' => $sku->id, 'quantity' => 3]], 17596);

        Http::assertSent(fn (ClientRequest $request): bool => $request['weight'] == 3000);
    }

    public function test_null_weight_falls_back_to_350_grams(): void
    {
        $sku = $this->createShippingSku(null);

        $this->fakeCostResponse();

        $this->service->getQuotes('', [['sku_id' => $sku->id, 'quantity' => 1]], 17596);

        Http::assertSent(fn (ClientRequest $request): bool => $request['weight'] == 350);
    }

    public function test_cargo_services_are_filtered_out_and_services_are_sorted_by_cost(): void
    {
        $sku = $this->createShippingSku(1000);

        $this->fakeCostResponse();

        $services = $this->service->getQuotes('', [['sku_id' => $sku->id, 'quantity' => 1]], 17596);

        $this->assertSame(['J&T', 'JNE'], array_values(array_unique(array_map(
            fn (array $service): string => $service['courier'],
            $services,
        ))));
        $this->assertNotContains('JTR', array_column($services, 'service'));
        $this->assertSame(13500, $services[0]['cost']);
    }

    public function test_courier_alias_matches_api_payload(): void
    {
        $sku = $this->createShippingSku(1000);

        $this->fakeCostResponse();

        $quote = $this->service->quoteFor('J&T', 'EZ', '', [['sku_id' => $sku->id, 'quantity' => 1]], 17596);

        $this->assertNotNull($quote);
        $this->assertSame('J&T', $quote['courier']);
        $this->assertSame(13500, $quote['cost']);
    }

    public function test_unknown_courier_returns_null_so_checkout_can_fail_closed(): void
    {
        $sku = $this->createShippingSku(1000);

        $this->fakeCostResponse();

        $this->assertNull(
            $this->service->quoteFor('GHOST', 'EXPRESS', '', [['sku_id' => $sku->id, 'quantity' => 1]], 17596)
        );
    }

    private function fakeCostResponse(): void
    {
        Http::fake([
            'rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['message' => 'Success', 'code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'name' => 'Jalur Nugraha Ekakurir (JNE)',
                        'code' => 'jne',
                        'service' => 'REG',
                        'description' => 'Layanan Reguler',
                        'cost' => 31000,
                        'etd' => '2 day',
                    ],
                    [
                        'name' => 'J&T Express',
                        'code' => 'jnt',
                        'service' => 'EZ',
                        'description' => 'Reguler',
                        'cost' => 13500,
                        'etd' => '',
                    ],
                    [
                        'name' => 'Jalur Nugraha Ekakurir (JNE)',
                        'code' => 'jne',
                        'service' => 'JTR',
                        'description' => 'Cargo',
                        'cost' => 115000,
                        'etd' => '8 day',
                    ],
                ],
            ]),
        ]);
    }
}
