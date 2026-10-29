<?php

namespace Tests\Feature\Api\V1;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\GuestCheckoutLog;
use App\Models\Order;
use App\Models\Payment;
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

    /**
     * Sejak 25-10-2026 lead WhatsApp tamu MENJADI pesanan.
     *
     * Dulu alur ini sengaja tidak membuat order (PRD §3.11 lama), akibatnya
     * kode GC-xxx tidak bisa ditemukan di panel admin sama sekali. Sekarang
     * order berstatus `awaiting_confirmation` supaya CS bisa memverifikasi
     * pembayaran VA/QRIS lalu mengonfirmasinya; stok tetap belum dipotong
     * sampai konfirmasi itu (lihat test di bawahnya).
     */
    public function test_guest_checkout_creates_awaiting_confirmation_order(): void
    {
        $initialOrdersCount = Order::count();

        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'guest_name' => 'Budi Santoso',
            'guest_phone' => '081234567890',
        ])->assertCreated();

        $this->assertEquals($initialOrdersCount + 1, Order::count());

        $orderNumber = $response->json('data.order_number');
        $this->assertIsString($orderNumber);

        $order = Order::query()->where('order_number', $orderNumber)->firstOrFail();

        $this->assertSame(Order::STATUS_AWAITING_CONFIRMATION, $order->status->value);
        $this->assertSame(Order::PAYMENT_METHOD_WHATSAPP, $order->payment_method);
        $this->assertNull($order->user_id);
        // Kode yang disebut pembeli ke CS harus tersimpan & bisa dicari.
        $this->assertSame($response->json('data.reference_code'), $order->guest_reference_code);
        $this->assertSame('Budi Santoso', $order->shipping_recipient_name);
        $this->assertSame('081234567890', $order->shipping_phone);

        // Ongkir belum diketahui: total = subtotal, bukan angka karangan.
        $this->assertEquals((float) $response->json('data.estimated_total'), (float) $order->total);
        $this->assertEquals(0.0, (float) $order->shipping_cost);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 1]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'payment_method' => Order::PAYMENT_METHOD_WHATSAPP,
            'status' => Payment::STATUS_PENDING,
        ]);
        // Log audit tetap tertaut ke order-nya.
        $this->assertDatabaseHas('guest_checkout_logs', ['order_id' => $order->id]);
        $this->assertTrue($order->canBeConfirmed());
    }

    /**
     * Submit dua kali dalam satu sesi = SATU pesanan.
     *
     * Pembeli yang menekan tombol lagi (atau mengulang setelah jeda) tidak
     * boleh menghasilkan pesanan kembar; isi pesanan lama disegarkan dari
     * keranjang terbaru dan nomor/nomornya tidak berubah.
     */
    public function test_second_submission_reuses_pending_whatsapp_order(): void
    {
        $token = 'dedupe-token-1';
        $initialOrdersCount = Order::count();

        $first = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
                'guest_name' => 'Budi Santoso',
            ])->assertCreated();

        $firstNumber = $first->json('data.order_number');
        $firstReference = $first->json('data.reference_code');

        // Submit ulang dengan jumlah berbeda di sesi yang sama.
        $second = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 3]],
                'guest_name' => 'Budi Santoso',
            ])->assertCreated();

        $this->assertEquals($initialOrdersCount + 1, Order::count());
        $this->assertSame($firstNumber, $second->json('data.order_number'));
        $this->assertSame($firstReference, $second->json('data.reference_code'));

        $order = Order::query()->where('order_number', $firstNumber)->firstOrFail();

        // Isi pesanan mengikuti keranjang terbaru, bukan yang pertama.
        $this->assertEquals(1, $order->items()->count());
        $this->assertSame(3, (int) $order->items()->first()->quantity);
        $this->assertEquals((float) $second->json('data.estimated_total'), (float) $order->total);
        $this->assertEquals((float) $order->total, (float) $order->payment->amount);

        // Satu log per order, bukan dua.
        $this->assertSame(1, GuestCheckoutLog::query()->where('order_id', $order->id)->count());
    }

    /**
     * Sesi berbeda tetap membuat pesanan berbeda — dedupe tidak boleh
     * menggabungkan dua pembeli yang kebetulan membeli barang sama.
     */
    public function test_different_session_creates_separate_whatsapp_order(): void
    {
        $initialOrdersCount = Order::count();

        $this->withHeader('X-Guest-Token', 'dedupe-token-a')
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
            ])->assertCreated();

        $this->withHeader('X-Guest-Token', 'dedupe-token-b')
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
            ])->assertCreated();

        $this->assertEquals($initialOrdersCount + 2, Order::count());
    }

    /**
     * Pesanan yang sudah kedaluwarsa atau dikonfirmasi admin TIDAK dipakai
     * ulang: submission berikutnya membuat order baru dengan nomor baru.
     */
    public function test_expired_whatsapp_order_is_not_reused(): void
    {
        $token = 'dedupe-token-expired';

        $first = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
            ])->assertCreated();

        Order::query()
            ->where('order_number', $first->json('data.order_number'))
            ->update(['status' => Order::STATUS_EXPIRED]);

        $second = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/v1/guest/whatsapp-checkout', [
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
            ])->assertCreated();

        $this->assertNotSame($first->json('data.order_number'), $second->json('data.order_number'));
        $this->assertNotSame($first->json('data.reference_code'), $second->json('data.reference_code'));
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

    public function test_guest_checkout_includes_shipping_address_and_courier(): void
    {
        $payload = [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'guest_name' => 'Lalu Explorer',
            'guest_phone' => '08123456789',
            'shipping_address' => [
                'street' => 'Jl. Pariwisata Senaru No. 12',
                'destination_id' => 751,
                'city' => 'Lombok Utara',
                'district' => 'Bayan',
                'subdistrict' => 'Senaru',
                'province' => 'NTB',
                'postal_code' => '83354',
            ],
            'courier' => 'jne',
            'note' => 'Mohon packing kayu',
        ];

        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', $payload);

        $response->assertCreated();

        $waUrl = urldecode($response->json('data.wa_url'));
        $this->assertStringContainsString('Jl. Pariwisata Senaru No. 12', $waUrl);
        $this->assertStringContainsString('Senaru', $waUrl);
        $this->assertStringContainsString('JNE (Reguler/YES)', $waUrl);
        $this->assertStringContainsString('Mohon packing kayu', $waUrl);

        $orderNumber = $response->json('data.order_number');
        $this->assertDatabaseHas('orders', [
            'order_number' => $orderNumber,
            'shipping_full_address' => 'Jl. Pariwisata Senaru No. 12, Senaru, Bayan, Lombok Utara, NTB, 83354',
            'shipping_courier' => 'jne',
            'shipping_destination_id' => 751,
        ]);
    }
}
