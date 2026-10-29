<?php

namespace Tests\Feature\Shipping;

use App\Models\AppSetting;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalogFixtures;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

/**
 * Order harus menyimpan snapshot tarif. Tarif kurir berubah kapan saja,
 * sedangkan angka yang dibayar pelanggan tidak boleh ikut berubah.
 */
class ShippingCheckoutSnapshotTest extends TestCase
{
    use CreatesCatalogFixtures;
    use FakesRajaOngkir;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        // Fake RajaOngkir dipasang lewat setUpTraits().
        parent::setUp();

        AppSetting::setValue('cod_enabled', 'true');
        AppSetting::setValue('cod_cs_whatsapp_number', '081234567890');

        $this->user = User::factory()->create();
    }

    public function test_registered_checkout_stores_shipping_snapshot(): void
    {
        $sku = $this->createShippingSku(1200);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/checkout/orders', [
            'shipping_address' => [
                'recipient_name' => 'Snapshot Tester',
                'phone' => '081122334455',
                'street' => 'Jl. Pariwisata Senaru No. 5',
                'city' => 'Jakarta Pusat',
                'province' => 'DKI Jakarta',
                'destination_id' => 17596,
            ],
            'items' => [
                ['sku_id' => $sku->id, 'quantity' => 1, 'price_seen' => 500000],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            // Nilai client tetap tidak dipercaya.
            'shipping_cost' => 1000,
            'payment_method' => 'cod',
        ]);

        $response->assertCreated();

        /** @var Order $order */
        $order = Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();

        $this->assertSame(14000.0, (float) $order->shipping_cost);
        $this->assertSame('jne', $order->shipping_courier_code);
        $this->assertSame(1200, $order->shipping_weight_grams);
        $this->assertSame(17596, $order->shipping_destination_id);
        $this->assertSame(17596, $order->shipping_address_snapshot['destination_id']);

        $snapshot = $order->shipping_quote_snapshot;

        $this->assertIsArray($snapshot);
        $this->assertSame('rajaongkir', $snapshot['provider']);
        $this->assertSame(746, $snapshot['origin_id']);
        $this->assertSame(17596, $snapshot['destination_id']);
        $this->assertSame(1200, $snapshot['weight_grams']);
        $this->assertSame('jne', $snapshot['courier_code']);
        $this->assertSame('REG', $snapshot['service']);
        $this->assertSame(14000, $snapshot['cost']);
        $this->assertSame('2 day', $snapshot['etd']);
        $this->assertSame('api', $snapshot['source']);
        $this->assertNotEmpty($snapshot['quoted_at']);
    }
}
