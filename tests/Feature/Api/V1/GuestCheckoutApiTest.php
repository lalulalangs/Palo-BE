<?php

namespace Tests\Feature\Api\V1;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\GuestCheckoutLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestCheckoutApiTest extends TestCase
{
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
        ]);

        AppSetting::setValue('whatsapp_cs', '081234567890');
    }

    public function test_guest_can_checkout_via_whatsapp_with_explicit_items(): void
    {
        $payload = [
            'items' => [
                [
                    'sku_id' => $this->sku->id,
                    'quantity' => 2,
                    'price_seen' => 1000, // Attempted price manipulation from client
                ],
            ],
            'guest_name' => 'Budi Pendaki',
            'guest_phone' => '087711223344',
            'note' => 'Packing kayu tolong ya kak',
        ];

        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tautan WhatsApp pesanan berhasil dibuat.')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'reference_code',
                    'wa_url',
                    'message',
                    'estimated_total',
                    'estimated_total_display',
                    'notice',
                ],
            ]);

        $data = $response->json('data');

        // Reference code format GC-YYYYMMDD-XXXX
        $this->assertStringStartsWith('GC-', $data['reference_code']);

        // Estimated total calculated from database (750.000 x 2 = 1.500.000), ignoring client price
        $this->assertEquals(1500000.0, (float) $data['estimated_total']);

        // WhatsApp URL checks
        $this->assertStringContainsString('https://wa.me/6281234567890', $data['wa_url']);
        $this->assertStringContainsString(rawurlencode('Tenda Dome Rinjani 4P'), $data['wa_url']);
        $this->assertStringContainsString(rawurlencode($data['reference_code']), $data['wa_url']);
    }

    public function test_guest_can_checkout_via_whatsapp_using_session_cart(): void
    {
        $guestToken = 'guest-session-cart-test';

        // Guest puts item in cart
        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 3,
            ])->assertOk();

        // Guest triggers checkout without providing items array
        $response = $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'guest_name' => 'Siti Senaru',
                'guest_phone' => '081299887766',
            ]);

        $response->assertCreated();
        $this->assertEquals(2250000.0, (float) $response->json('data.estimated_total'));
    }

    public function test_guest_checkout_does_not_create_order_in_orders_table(): void
    {
        $initialOrdersCount = Order::count();

        $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'guest_name' => 'No Order Test',
        ])->assertCreated();

        // PRD §3.11: Must NEVER create row in orders table
        $this->assertEquals($initialOrdersCount, Order::count());
    }

    public function test_guest_checkout_does_not_lock_or_deduct_inventory_stock(): void
    {
        $initialStock = $this->sku->stock;
        $initialAvailable = $this->sku->available_stock;

        $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 5],
            ],
        ])->assertCreated();

        $freshSku = $this->sku->fresh();
        $this->assertEquals($initialStock, $freshSku->stock);
        $this->assertEquals($initialAvailable, $freshSku->available_stock);
    }

    public function test_guest_checkout_creates_audit_log_in_guest_checkout_logs(): void
    {
        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 2],
            ],
            'guest_name' => 'Audit Tester',
            'guest_phone' => '085544332211',
            'note' => 'Mohon diproses cepat',
        ]);

        $response->assertCreated();
        $refCode = $response->json('data.reference_code');

        $this->assertDatabaseHas('guest_checkout_logs', [
            'wa_cs_number_used' => '6281234567890',
            'estimated_total' => 1500000,
        ]);

        $log = GuestCheckoutLog::latest('id')->firstOrFail();
        $this->assertStringContainsString($refCode, $log->wa_message_text);
        $this->assertStringContainsString('Tenda Dome Rinjani 4P', $log->wa_message_text);
        $this->assertIsArray($log->cart_snapshot);
        $this->assertEquals($this->sku->id, $log->cart_snapshot[0]['sku_id']);
    }

    public function test_guest_checkout_uses_dynamic_whatsapp_cs_number_from_app_settings(): void
    {
        // Update CS WhatsApp number in settings
        AppSetting::setValue('whatsapp_cs', '087812345678');

        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated();
        $this->assertStringContainsString('https://wa.me/6287812345678', $response->json('data.wa_url'));
    }

    public function test_guest_checkout_fails_validation_when_items_and_session_cart_are_empty(): void
    {
        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [],
            'guest_name' => 'Empty User',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_guest_can_submit_custom_wholesale_inquiry(): void
    {
        $payload = [
            'name' => 'Komunitas Mapala Rinjani',
            'phone' => '081999888777',
            'quantity' => 50,
            'note' => 'Kebutuhan jaket windbreaker bordir custom logo angkatan',
        ];

        $response = $this->postJson('/api/v1/guest/custom-inquiry', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estimated_total', 0)
            ->assertJsonPath('data.estimated_total_display', '[Harga dari Admin]');

        $refCode = $response->json('data.reference_code');
        $this->assertStringStartsWith('INQ-', $refCode);

        // Audit log created
        $this->assertDatabaseHas('guest_checkout_logs', [
            'wa_cs_number_used' => '6281234567890',
            'estimated_total' => 0,
        ]);
    }
}
