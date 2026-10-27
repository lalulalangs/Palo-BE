<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class GuestCheckoutDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        AppSetting::setValue('whatsapp_cs', '081234567890');
        AppSetting::setValue('cod_confirmation_timeout_hours', '24');

        $category = Category::create([
            'name' => 'Outdoor Gear',
            'slug' => 'outdoor-gear',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Rinjani',
            'slug' => 'jaket-rinjani',
            'base_price' => 250000,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Hitam / Size L',
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'JKT-RNJ-BLK-L',
            'price_override' => 250000,
            'stock' => 50,
            'is_active' => true,
        ]);
    }

    public function test_guest_checkout_reuses_existing_order_for_same_palorinjani_guest_token_cookie(): void
    {
        $guestToken = 'test-guest-token-uuid-123';

        $payload = [
            'guest_name' => 'Tamu Pertama',
            'guest_phone' => '081299998888',
            'note' => 'Catatan 1',
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ];

        // Request pertama
        $res1 = $this->withUnencryptedCookie('palorinjani_guest_token', $guestToken)
            ->postJson('/api/v1/guest/whatsapp-checkout', $payload);

        $res1->assertStatus(201);
        $orderNumber1 = $res1->json('data.order_number');
        $this->assertDatabaseCount('orders', 1);

        // Request kedua dengan token cookie yang sama tapi kuantitas bertambah
        $payload2 = [
            'guest_name' => 'Tamu Pertama Update',
            'guest_phone' => '081299998888',
            'note' => 'Catatan Update',
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 2],
            ],
        ];

        $res2 = $this->withUnencryptedCookie('palorinjani_guest_token', $guestToken)
            ->postJson('/api/v1/guest/whatsapp-checkout', $payload2);

        $res2->assertStatus(201);
        $orderNumber2 = $res2->json('data.order_number');

        // Nomor pesanan harus sama dan tidak ada baris orders baru yang dibuat
        $this->assertSame($orderNumber1, $orderNumber2);
        $this->assertDatabaseCount('orders', 1);

        $order = Order::first();
        $this->assertEquals(500000, (float) $order->total);
        $this->assertSame('Tamu Pertama Update', $order->shipping_recipient_name);
    }

    public function test_guest_checkout_reuses_order_when_matching_phone_number_even_with_different_session(): void
    {
        $payload1 = [
            'guest_name' => 'Pembeli WhatsApp',
            'guest_phone' => '081234567890',
            'note' => 'Order pertama',
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ];

        // Sesi A membuat order
        $res1 = $this->withHeader('X-Guest-Token', 'session-token-aaa')
            ->postJson('/api/v1/guest/whatsapp-checkout', $payload1);

        $res1->assertStatus(201);
        $orderNumber1 = $res1->json('data.order_number');
        $this->assertDatabaseCount('orders', 1);

        // Sesi B (misal browser berbeda atau incognito) submit dengan nomor WhatsApp berformat 628...
        $payload2 = [
            'guest_name' => 'Pembeli WhatsApp',
            'guest_phone' => '6281234567890',
            'note' => 'Order kedua dari sesi lain',
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 3],
            ],
        ];

        $res2 = $this->withHeader('X-Guest-Token', 'session-token-bbb')
            ->postJson('/api/v1/guest/whatsapp-checkout', $payload2);

        $res2->assertStatus(201);
        $orderNumber2 = $res2->json('data.order_number');

        // Harus mendeteksi nomor telepon yang sama dan memakai ulang order
        $this->assertSame($orderNumber1, $orderNumber2);
        $this->assertDatabaseCount('orders', 1);

        $order = Order::first();
        $this->assertEquals(750000, (float) $order->total);
    }

    public function test_short_or_dummy_phone_number_does_not_trigger_cross_session_deduplication(): void
    {
        // Sesi A dengan dummy phone "0"
        $res1 = $this->withHeader('X-Guest-Token', 'session-token-dummy-1')
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'guest_name' => 'User A',
                'guest_phone' => '0',
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
            ]);
        $res1->assertStatus(201);
        $orderNumber1 = $res1->json('data.order_number');

        // Sesi B dengan dummy phone "0" tapi sesi berbeda: tidak boleh menimpa order Sesi A
        $res2 = $this->withHeader('X-Guest-Token', 'session-token-dummy-2')
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'guest_name' => 'User B',
                'guest_phone' => '0',
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
            ]);
        $res2->assertStatus(201);
        $orderNumber2 = $res2->json('data.order_number');

        $this->assertNotSame($orderNumber1, $orderNumber2);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_guest_order_creation_rate_limiter_blocks_excessive_submissions(): void
    {
        RateLimiter::clear('guest-orders:127.0.0.1');

        $payload = [
            'guest_name' => 'Spammer',
            'guest_phone' => '08999999999',
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ];

        // 5 request pertama diizinkan
        for ($i = 0; $i < 5; $i++) {
            $res = $this->postJson('/api/v1/guest/whatsapp-checkout', $payload);
            $res->assertStatus(201);
        }

        // Request ke-6 harus ditolak dengan HTTP 429 Too Many Requests
        $res = $this->postJson('/api/v1/guest/whatsapp-checkout', $payload);
        $res->assertStatus(429);
    }
}
