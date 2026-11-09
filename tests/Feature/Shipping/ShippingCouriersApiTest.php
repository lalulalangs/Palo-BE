<?php

namespace Tests\Feature\Shipping;

use App\Models\AppSetting;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCatalogFixtures;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

class ShippingCouriersApiTest extends TestCase
{
    use CreatesCatalogFixtures;
    use FakesRajaOngkir;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function rajaOngkirCostBody(): array
    {
        return [
            'meta' => ['message' => 'Success Calculate Domestic Shipping cost', 'code' => 200, 'status' => 'success'],
            'data' => [
                [
                    'name' => 'Jalur Nugraha Ekakurir (JNE)',
                    'code' => 'jne',
                    'service' => 'REG',
                    'description' => 'Layanan Reguler',
                    'cost' => 25000,
                    'etd' => '2-3 hari',
                ],
                [
                    'name' => 'POS Indonesia',
                    'code' => 'pos',
                    'service' => 'Pos Reguler',
                    'description' => 'Pos Reguler',
                    'cost' => 18000,
                    'etd' => '2-4 hari',
                ],
            ],
        ];
    }

    public function test_couriers_endpoint_returns_default_enabled_couriers(): void
    {
        $response = $this->getJson('/api/v1/shipping/couriers');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'jne')
            ->assertJsonPath('data.0.name', 'JNE')
            ->assertJsonPath('data.0.label', 'JNE')
            ->assertJsonPath('data.0.desc', 'Reguler / YES')
            ->assertJsonPath('data.1.id', 'jnt')
            ->assertJsonPath('data.1.name', 'J&T Express')
            ->assertJsonPath('data.1.label', 'J&T Express')
            ->assertJsonPath('data.1.desc', 'Reguler / EZ');

        Http::assertNothingSent();
    }

    public function test_couriers_endpoint_reflects_app_setting_changes_immediately(): void
    {
        AppSetting::setValue('shipping_enabled_couriers', 'jne:pos');

        $response = $this->getJson('/api/v1/shipping/couriers');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'jne')
            ->assertJsonPath('data.0.name', 'JNE')
            ->assertJsonPath('data.1.id', 'pos')
            ->assertJsonPath('data.1.name', 'POS Indonesia')
            ->assertJsonPath('data.1.label', 'POS Indonesia')
            ->assertJsonPath('data.1.desc', 'Kilat Khusus');

        Http::assertNothingSent();
    }

    public function test_guest_cod_checkout_validates_courier_dynamically(): void
    {
        AppSetting::setValue('shipping_enabled_couriers', 'jne:pos');
        AppSetting::setValue('cod_enabled', 'true');

        $sku = $this->createShippingSku(1000);

        $validPayload = [
            'shipping_address' => [
                'recipient_name' => 'Budi Santoso',
                'phone' => '081234567890',
                'street' => 'Jl. Pariwisata Senaru',
                'city' => 'Lombok Utara',
                'province' => 'Nusa Tenggara Barat',
                'courier' => 'pos',
            ],
            'courier' => 'pos',
            'items' => [
                ['sku_id' => $sku->id, 'quantity' => 1],
            ],
        ];

        // Kurir POS yang aktif harus lolos
        $response = $this->postJson('/api/v1/guest/cod-checkout', $validPayload);
        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'menunggu_konfirmasi_cs');

        // Kurir J&T yang dinonaktifkan harus ditolak (422)
        $invalidPayload = $validPayload;
        $invalidPayload['courier'] = 'jnt';
        $invalidPayload['shipping_address']['courier'] = 'jnt';

        $failResponse = $this->postJson('/api/v1/guest/cod-checkout', $invalidPayload);
        $failResponse->assertStatus(422)
            ->assertJsonValidationErrors(['courier', 'shipping_address.courier']);
    }

    public function test_guest_auto_shipping_quote_filters_and_resolves_pos_courier(): void
    {
        AppSetting::setValue('shipping_origin_subdistrict_id', '746');
        AppSetting::setValue('shipping_enabled_couriers', 'jne:pos');

        $sku = $this->createShippingSku(1000);

        $payload = [
            'guest_name' => 'Ahmad Rinjani',
            'guest_phone' => '087812345678',
            'courier' => 'pos',
            'shipping_address' => [
                'destination_id' => 17596,
                'street' => 'Jl. Pendakian Senaru',
                'city' => 'Lombok Utara',
                'province' => 'Nusa Tenggara Barat',
            ],
            'items' => [
                ['sku_id' => $sku->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.estimated_total', 518000);

        $order = Order::where('order_number', $response->json('data.order_number'))->first();
        $this->assertNotNull($order);
        $this->assertSame('POS Indonesia', $order->shipping_courier);
        $this->assertSame('pos', $order->shipping_courier_code);
        $this->assertSame(18000.0, (float) $order->shipping_cost);
    }
}
