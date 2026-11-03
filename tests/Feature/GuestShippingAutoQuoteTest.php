<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

class GuestShippingAutoQuoteTest extends TestCase
{
    use FakesRajaOngkir;
    use RefreshDatabase;

    protected Category $category;

    protected Product $product;

    protected ProductVariant $variant;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Perlengkapan Gunung',
            'slug' => 'perlengkapan-gunung',
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Tenda Dome Rinjani 4P',
            'slug' => 'tenda-dome-rinjani-4p',
            'base_price' => 750000,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Warna Kuning',
            'attributes' => ['Warna' => 'Kuning'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'TDR-KUN-4P',
            'price_override' => null,
            'stock' => 12,
            'is_active' => true,
            'weight_grams' => 2000,
        ]);

        AppSetting::setValue('whatsapp_cs', '081234567890');
        AppSetting::setValue('cod_enabled', 'true');
    }

    public function test_guest_whatsapp_checkout_auto_quotes_cheapest_service(): void
    {
        $payload = [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'guest_name' => 'Ahmad Dahlan',
            'guest_phone' => '081234567890',
            'courier' => 'jne',
            'shipping_address' => [
                'street' => 'Jl. Pariwisata Senaru No. 45',
                'destination_id' => 17596,
                'city' => 'JAKARTA PUSAT',
                'province' => 'DKI JAKARTA',
            ],
        ];

        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $orderNumber = $response->json('data.order_number');
        $order = Order::where('order_number', $orderNumber)->firstOrFail();

        // 750.000 barang + 14.000 ongkir JNE REG
        $this->assertEquals(750000.0, (float) $order->subtotal);
        $this->assertEquals(14000.0, (float) $order->shipping_cost);
        $this->assertEquals(764000.0, (float) $order->total);
        $this->assertEquals('REG', $order->shipping_service);
        $this->assertNotNull($order->shipping_quote_snapshot);

        $waUrl = urldecode($response->json('data.wa_url'));
        $this->assertStringContainsString('14.000', $waUrl);
        $this->assertStringContainsString('JNE - REG', $waUrl);
        $this->assertStringContainsString('764.000', $waUrl);
    }

    public function test_guest_cod_checkout_auto_quotes_cheapest_service(): void
    {
        $payload = [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'courier' => 'jnt',
            'shipping_address' => [
                'recipient_name' => 'Ahmad Dahlan',
                'phone' => '081234567890',
                'street' => 'Jl. Pariwisata Senaru No. 45',
                'destination_id' => 17596,
                'city' => 'JAKARTA PUSAT',
                'province' => 'DKI JAKARTA',
            ],
        ];

        $response = $this->postJson('/api/v1/guest/cod-checkout', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $orderNumber = $response->json('data.order_number');
        $order = Order::where('order_number', $orderNumber)->firstOrFail();

        // J&T EZ = 13.500
        $this->assertEquals(750000.0, (float) $order->subtotal);
        $this->assertEquals(13500.0, (float) $order->shipping_cost);
        $this->assertEquals(763500.0, (float) $order->total);
        $this->assertEquals('EZ', $order->shipping_service);
    }

    public function test_guest_checkout_gracefully_falls_back_when_rajaongkir_outage(): void
    {
        $this->rajaOngkirOutage = true;

        $payload = [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'guest_name' => 'Ahmad Dahlan',
            'guest_phone' => '081234567890',
            'courier' => 'jne',
            'shipping_address' => [
                'street' => 'Jl. Pariwisata Senaru No. 45',
                'destination_id' => 17596,
                'city' => 'JAKARTA PUSAT',
                'province' => 'DKI JAKARTA',
            ],
        ];

        // Harus tetap sukses (tidak boleh gagal 500 karena outage)
        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $orderNumber = $response->json('data.order_number');
        $order = Order::where('order_number', $orderNumber)->firstOrFail();

        // Fallback: ongkir 0, total = subtotal
        $this->assertEquals(750000.0, (float) $order->subtotal);
        $this->assertEquals(0.0, (float) $order->shipping_cost);
        $this->assertEquals(750000.0, (float) $order->total);

        $waUrl = urldecode($response->json('data.wa_url'));
        $this->assertStringContainsString('Ongkir: akan dikonfirmasi CS', $waUrl);
        $this->assertStringContainsString('750.000', $waUrl);
    }
}
