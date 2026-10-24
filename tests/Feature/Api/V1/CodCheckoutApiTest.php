<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\GuestCheckoutLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

class CodCheckoutApiTest extends TestCase
{
    use FakesRajaOngkir;
    use RefreshDatabase;

    protected User $user;

    protected Category $category;

    protected Product $product;

    protected ProductVariant $variant;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Pendaki Rinjani',
            'email' => 'pendaki@rinjani.test',
            'password_hash' => Hash::make('Secret123!'),
            'phone' => '081234567890',
        ]);

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
            'stock' => 10,
            'is_active' => true,
        ]);

        AppSetting::setValue('cod_enabled', 'true');
        AppSetting::setValue('cod_cs_whatsapp_number', '081234567890');
    }

    public function test_registered_user_can_checkout_with_cod(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Budi Pendaki',
                'phone' => '087711223344',
                'street' => 'Jl. Raya Sembalun No. 12',
                'city' => 'Lombok Timur',
                'province' => 'Nusa Tenggara Barat',
                'district' => 'Sembalun',
                'postal_code' => '83656',
                'notes' => 'Titip di satpam ya',
            ],
            'items' => [
                [
                    'sku_id' => $this->sku->id,
                    'quantity' => 2,
                    'price_seen' => 750000,
                ],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 25000,
            'payment_method' => 'cod',
            'note' => 'Titip di satpam ya',
        ];

        $response = $this->postJson('/api/v1/checkout/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pesanan berhasil dibuat.')
            ->assertJsonPath('data.status', 'menunggu_konfirmasi_cs')
            ->assertJsonPath('data.subtotal', 1500000)
            ->assertJsonPath('data.shipping_cost', 14000)
            ->assertJsonPath('data.grand_total', 1514000)
            ->assertJsonPath('data.payment.method', 'cod');

        $codRef = $response->json('data.cod_reference_code');
        $this->assertNotNull($codRef);
        $this->assertMatchesRegularExpression('/^COD-\d{8}-[A-Z0-9]{5}$/', $codRef);

        $orderNumber = $response->json('data.order_number');
        $this->assertNotNull($orderNumber);

        // Verify order in database
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        $this->assertEquals(OrderStatus::AwaitingConfirmation, $order->status);
        $this->assertSame(Order::PAYMENT_METHOD_COD, $order->payment_method);
        $this->assertSame($codRef, $order->cod_reference_code);
        $this->assertNull($order->stock_locked_until);
        $this->assertSame($this->user->id, $order->user_id);
        $this->assertSame(1514000.0, (float) $order->total);

        // Stock in warehouse must NOT be deducted yet at checkout time
        $this->assertSame(10, $this->sku->fresh()->stock);

        // Verify payment record
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('cod', $payment->gateway);
        $this->assertSame('cod', $payment->payment_method);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertNull($payment->expired_at);

        // Verify WhatsApp URL and message content according to Spec §3.1
        $whatsappUrl = $response->json('data.whatsapp_url');
        $this->assertNotNull($whatsappUrl);
        $this->assertStringContainsString('https://wa.me/6281234567890', $whatsappUrl);
        $this->assertStringContainsString(rawurlencode($codRef), $whatsappUrl);
        $this->assertStringContainsString(rawurlencode('Tenda Dome Rinjani 4P'), $whatsappUrl);
        $this->assertStringContainsString(rawurlencode('Rp 1.500.000'), $whatsappUrl);
        $this->assertStringContainsString(rawurlencode('Budi Pendaki'), $whatsappUrl);

        // Verify OrderStatusHistory
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_AWAITING_CONFIRMATION,
            'note' => 'Pesanan COD dibuat oleh pembeli, menunggu konfirmasi CS.',
        ]);
    }

    public function test_guest_user_can_checkout_with_cod(): void
    {
        $guestToken = 'guest-cod-token-xyz';

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Pendaki Guest',
                'phone' => '085522334455',
                'street' => 'Jl. Pariwisata Senaru No. 5',
                'city' => 'Lombok Utara',
                'province' => 'Nusa Tenggara Barat',
                'district' => 'Bayan',
                'postal_code' => '83354',
                'notes' => 'Hubungi via WhatsApp dulu ya mas',
            ],
            'items' => [
                [
                    'sku_id' => $this->sku->id,
                    'quantity' => 1,
                    'price_seen' => 750000,
                ],
            ],
            // Dikirim dengan sengaja: guest COD tidak boleh mempercayai nilai
            // ongkir/kurir dari client, semuanya harus diabaikan server.
            'shipping_courier' => 'SiCepat',
            'shipping_service' => 'BEST',
            'shipping_cost' => 30000,
            'note' => 'Hubungi via WhatsApp dulu ya mas',
        ];

        $response = $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/guest/cod-checkout', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pesanan COD berhasil dibuat.')
            ->assertJsonPath('data.status', 'menunggu_konfirmasi_cs')
            ->assertJsonPath('data.subtotal', 750000)
            ->assertJsonPath('data.shipping_cost', 0)
            ->assertJsonPath('data.grand_total', 750000);

        $codRef = $response->json('data.cod_reference_code');
        $this->assertNotNull($codRef);
        $this->assertMatchesRegularExpression('/^COD-\d{8}-[A-Z0-9]{5}$/', $codRef);

        $orderNumber = $response->json('data.order_number');
        $this->assertNotNull($orderNumber);

        // Verify order in database with user_id = null
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        $this->assertNull($order->user_id);
        $this->assertTrue($order->isGuest());
        $this->assertTrue($order->isCod());
        $this->assertEquals(OrderStatus::AwaitingConfirmation, $order->status);
        $this->assertSame($codRef, $order->cod_reference_code);
        $this->assertNull($order->stock_locked_until);
        $this->assertNull($order->shipping_courier);
        $this->assertSame(0.0, (float) $order->shipping_cost);

        // Verify item snapshot
        $this->assertCount(1, $order->items);
        $item = $order->items->first();
        $this->assertSame('Tenda Dome Rinjani 4P', $item->product_name_snapshot);
        $this->assertSame('Warna Kuning', $item->variant_name_snapshot);

        // Stock in warehouse must NOT be deducted yet
        $this->assertSame(10, $this->sku->fresh()->stock);

        // Verify GuestCheckoutLog created with order_id
        $log = GuestCheckoutLog::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($guestToken, $log->session_id);
        $this->assertSame(750000.0, (float) $log->estimated_total);
        $this->assertStringContainsString($codRef, $log->wa_message_text);
        // Ongkir belum final: CS mengonfirmasinya lewat WhatsApp.
        $this->assertStringContainsString('Ongkir: akan dikonfirmasi CS', $log->wa_message_text);
        $this->assertStringNotContainsString('Rp 30.000', $log->wa_message_text);

        // Verify WhatsApp URL
        $waUrl = $response->json('data.whatsapp_url');
        $this->assertStringContainsString('https://wa.me/6281234567890', $waUrl);
        $this->assertStringContainsString(rawurlencode($codRef), $waUrl);
    }

    public function test_guest_cod_checkout_using_session_cart(): void
    {
        $guestToken = 'guest-session-cart-cod';

        // Add item to guest cart via cart endpoint
        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        // Guest checkout without explicit items array in payload
        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Dewi Pelari',
                'phone' => '081299887766',
                'street' => 'Jl. Danau Segara Anak No. 1',
                'city' => 'Mataram',
                'province' => 'Nusa Tenggara Barat',
            ],
            'shipping_cost' => 15000,
        ];

        $response = $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/guest/cod-checkout', $payload);

        $response->assertCreated();
        $this->assertEquals(1500000.0, (float) $response->json('data.subtotal'));
        // Ongkir dari client diabaikan; CS mengonfirmasi via WhatsApp.
        $this->assertEquals(1500000.0, (float) $response->json('data.grand_total'));
        $this->assertEquals(0.0, (float) $response->json('data.shipping_cost'));

        // Guest cart must be cleared after checkout
        $cartCheck = $this->withHeader('X-Guest-Token', $guestToken)
            ->getJson('/api/v1/cart');
        $cartCheck->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_server_revalidation_ignores_manipulated_client_prices(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Hacker Coba-Coba',
                'phone' => '081999999999',
                'street' => 'Jl. Tipu-Tipu No. 1',
                'city' => 'Jakarta',
                'province' => 'DKI Jakarta',
            ],
            'items' => [
                [
                    'sku_id' => $this->sku->id,
                    'quantity' => 2,
                    'price_seen' => 1000, // Client attempts to buy 750k item for 1k
                ],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'cod',
            'acknowledge_price_change' => true,
        ];

        $response = $this->postJson('/api/v1/checkout/orders', $payload);

        $response->assertCreated();
        // Server menghitung ulang dari harga efektif SKU, bukan price_seen client:
        // 750.000 x 2 = 1.500.000 + ongkir server (14.000) = 1.514.000.
        $this->assertEquals(1500000.0, (float) $response->json('data.subtotal'));
        $this->assertEquals(1514000.0, (float) $response->json('data.grand_total'));
    }

    public function test_checkout_rejects_cod_when_cod_is_disabled_in_settings(): void
    {
        AppSetting::setValue('cod_enabled', 'false');

        // Test registered user
        Sanctum::actingAs($this->user);
        $registeredPayload = [
            'shipping_address' => [
                'recipient_name' => 'Pendaki',
                'phone' => '081234567890',
                'street' => 'Jl. Rinjani',
                'city' => 'Lombok Timur',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'cod',
        ];

        $regResponse = $this->postJson('/api/v1/checkout/orders', $registeredPayload);
        $regResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);
        $this->assertStringContainsString(
            'Metode pembayaran COD sedang dinonaktifkan.',
            $regResponse->json('errors.payment_method.0')
        );

        // Test guest user
        $guestPayload = [
            'shipping_address' => [
                'recipient_name' => 'Guest Pendaki',
                'phone' => '081234567890',
                'street' => 'Jl. Rinjani',
                'city' => 'Lombok Timur',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ];

        $guestResponse = $this->postJson('/api/v1/guest/cod-checkout', $guestPayload);
        $guestResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);
        $this->assertStringContainsString(
            'Metode pembayaran COD sedang dinonaktifkan.',
            $guestResponse->json('errors.payment_method.0')
        );
    }

    public function test_cod_whatsapp_url_uses_dynamic_whatsapp_number_from_settings(): void
    {
        AppSetting::setValue('cod_cs_whatsapp_number', '087899887766');

        Sanctum::actingAs($this->user);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Pendaki Rinjani',
                'phone' => '081234567890',
                'street' => 'Jl. Sembalun',
                'city' => 'Lombok Timur',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'cod',
        ];

        $response = $this->postJson('/api/v1/checkout/orders', $payload);
        $response->assertCreated();

        $whatsappUrl = $response->json('data.whatsapp_url');
        $this->assertStringContainsString('https://wa.me/6287899887766', $whatsappUrl);
    }

    public function test_order_detail_and_list_endpoints_expose_cod_attributes(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Pendaki Rinjani',
                'phone' => '081234567890',
                'street' => 'Jl. Sembalun',
                'city' => 'Lombok Timur',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'cod',
        ];

        $createResponse = $this->postJson('/api/v1/checkout/orders', $payload);
        $createResponse->assertCreated();
        $orderNumber = $createResponse->json('data.order_number');
        $codRef = $createResponse->json('data.cod_reference_code');

        // Test GET /orders/{orderNumber}
        $detailResponse = $this->getJson("/api/v1/orders/{$orderNumber}");
        $detailResponse->assertOk()
            ->assertJsonPath('data.is_cod', true)
            ->assertJsonPath('data.cod_reference_code', $codRef)
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.status', 'menunggu_konfirmasi_cs')
            ->assertJsonPath('data.confirmed_at', null)
            ->assertJsonPath('data.refund_status', null);

        // Test GET /orders
        $listResponse = $this->getJson('/api/v1/orders');
        $listResponse->assertOk()
            ->assertJsonPath('data.0.is_cod', true)
            ->assertJsonPath('data.0.cod_reference_code', $codRef)
            ->assertJsonPath('data.0.payment_method', 'cod');
    }

    public function test_checkout_fails_when_sku_has_insufficient_stock(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Pendaki Haus Tenda',
                'phone' => '081234567890',
                'street' => 'Jl. Sembalun',
                'city' => 'Lombok Timur',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 100], // stock only 10
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'cod',
        ];

        $response = $this->postJson('/api/v1/checkout/orders', $payload);
        $response->assertStatus(409);
    }
}
