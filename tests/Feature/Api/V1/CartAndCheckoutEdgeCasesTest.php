<?php

namespace Tests\Feature\Api\V1;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesRajaOngkir;
use Tests\Support\FakesDokuCheckout;
use Tests\TestCase;

class CartAndCheckoutEdgeCasesTest extends TestCase
{
    use FakesDokuCheckout;
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

        $this->configureDokuForTests();
        $this->fakeDokuCheckout();

        $this->user = User::create([
            'name' => 'Edge Case Hunter',
            'email' => 'edge@example.test',
            'password_hash' => Hash::make('Password123!'),
            'phone' => '081234567890',
        ]);

        $this->category = Category::create([
            'name' => 'Tenda & Shelter',
            'slug' => 'tenda-shelter',
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Flysheet Ultralight 3x3',
            'slug' => 'flysheet-ultralight-3x3',
            'base_price' => 125000,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Olive Green',
            'attributes' => ['Warna' => 'Olive Green'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'FLY-UL-OLV',
            'price_override' => null,
            'stock' => 5,
            'is_active' => true,
        ]);

        AppSetting::setValue('whatsapp_cs', '081234567890');
    }

    /**
     * Edge Case 1: Client sends duplicated SKU lines in checkout payload.
     * Total stock is 5. Client sends 3 + 3 = 6 in two separate items of the same SKU.
     * System must consolidate and REJECT because 6 > 5.
     */
    public function test_cannot_checkout_duplicate_skus_that_exceed_total_available_stock(): void
    {
        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Attacker',
                'phone' => '081234567890',
                'street' => 'Jl. Eksploitasi No. 1',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 3, 'price_seen' => 125000],
                ['sku_id' => $this->sku->id, 'quantity' => 3, 'price_seen' => 125000],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 15000,
            'payment_method' => 'qris',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        // Must be rejected with 409 because 3 + 3 = 6 > available stock (5)
        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'stock_unavailable')
            ->assertJsonPath('available_stock', 5)
            ->assertJsonPath('requested_quantity', 6);
    }

    /**
     * Edge Case 2: Client tries to checkout a soft-deleted product or deactivated SKU.
     */
    public function test_cannot_checkout_inactive_sku_or_soft_deleted_product(): void
    {
        // Deactivate SKU
        $this->sku->update(['is_active' => false]);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Tester',
                'phone' => '081234567890',
                'street' => 'Jl. Rinjani',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1, 'price_seen' => 125000],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 15000,
            'payment_method' => 'qris',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'stock_unavailable');

        // Now activate SKU, but soft-delete the parent product
        $this->sku->update(['is_active' => true]);
        $this->product->delete();

        $responseDeleted = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $responseDeleted->assertStatus(409)
            ->assertJsonPath('error_code', 'stock_unavailable');
    }

    /**
     * Edge Case 3: Cannot add inactive SKU or soft-deleted product to cart.
     */
    public function test_cannot_add_inactive_sku_or_soft_deleted_product_to_cart(): void
    {
        $this->sku->update(['is_active' => false]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 1,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['sku_id']);
    }

    /**
     * Edge Case 4: Cart display automatically marks soft-deleted product as out of stock.
     */
    public function test_cart_marks_soft_deleted_product_as_out_of_stock_with_warning(): void
    {
        // User adds item to cart when active
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        // Admin soft-deletes the product
        $this->product->delete();

        // User views their cart
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('data.items.0.sku_id', $this->sku->id)
            ->assertJsonPath('data.items.0.product_name', 'Produk Tidak Lagi Tersedia')
            ->assertJsonPath('data.items.0.is_out_of_stock', true)
            ->assertJsonPath('data.items.0.available_stock', 0);
    }

    /**
     * Edge Case 5: Cart merge ignores inactive or soft-deleted products.
     */
    public function test_cart_merge_upon_login_ignores_inactive_or_deleted_products(): void
    {
        $guestToken = 'guest-edge-merge';

        // Guest adds SKU 1 (qty 2)
        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        // Product gets soft-deleted before user logs in
        $this->product->delete();

        // User logs in; login yang menggabungkan keranjang tamu
        // (endpoint /cart/merge sudah dihapus).
        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/auth/login', [
                'email' => 'edge@example.test',
                'password' => 'Password123!',
                'guest_token' => $guestToken,
            ])->assertOk();

        // User's permanent cart must NOT contain the deleted product
        $this->assertDatabaseMissing('cart_items', [
            'sku_id' => $this->sku->id,
        ]);
    }

    /**
     * Edge Case 6: Guest checkout ignores inactive or deleted products.
     */
    public function test_guest_checkout_ignores_inactive_or_deleted_products(): void
    {
        $this->product->delete();

        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'guest_name' => 'Ghost Buyer',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    /**
     * Edge Case 7: Guest checkout consolidates duplicate SKUs into a single clean line in WhatsApp message.
     */
    public function test_guest_checkout_consolidates_duplicate_skus_in_message(): void
    {
        $response = $this->postJson('/api/v1/guest/whatsapp-checkout', [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 2],
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'guest_name' => 'Consolidate Buyer',
        ]);

        $response->assertCreated();
        $msg = $response->json('data.message');

        // Total quantity should be 3
        $this->assertStringContainsString('Flysheet Ultralight 3x3 (Olive Green) x 3', $msg);
        $this->assertEquals(375000.0, (float) $response->json('data.estimated_total'));
    }

    /**
     * Edge Case 8: Soft lock expires cleanly after the payment window, restoring available stock.
     */
    public function test_soft_lock_expires_cleanly_after_payment_window_and_restores_available_stock(): void
    {
        // Physical stock is 5.
        // User creates order for 4 units
        $order = Order::create([
            'order_number' => 'UJN-20261011-EXPIRE1',
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 125000 * 4,
            'total' => 125000 * 4,
            'stock_locked_until' => now()->addMinutes(60),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Flysheet Ultralight 3x3',
            'variant_name_snapshot' => 'Olive Green',
            'sku_code_snapshot' => 'FLY-UL-OLV',
            'price_snapshot' => 125000,
            'quantity' => 4,
            'subtotal' => 125000 * 4,
        ]);

        // While lock is active, available stock is 5 - 4 = 1.
        $this->assertEquals(1, $this->sku->fresh()->available_stock);

        // Fast-forward time past the payment window (60 menit).
        $this->travel(61)->minutes();

        // Lock has expired! Available stock must now be 5 again!
        $this->assertEquals(5, $this->sku->fresh()->available_stock);

        // Reset travel
        $this->travelBack();
    }

    /**
     * Edge Case 9: Voucher code trimming and case insensitivity.
     */
    public function test_voucher_code_is_case_insensitive_and_trimmed(): void
    {
        Voucher::create([
            'code' => 'DISC25',
            'type' => 'fixed',
            'value' => 25000,
            'min_purchase' => 100000,
            'quota' => 10,
            'used_count' => 0,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'is_active' => true,
        ]);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Voucher Tester',
                'phone' => '081234567890',
                'street' => 'Jl. Pariwisata',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 10000,
            'payment_method' => 'qris',
            'voucher_code' => '  disc25  ', // lower case with leading and trailing spaces
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        // Subtotal = 125.000, Discount = 25.000, shipping server = 14.000 -> Grand Total = 114.000
        $response->assertCreated()
            ->assertJsonPath('data.discount_total', 25000)
            ->assertJsonPath('data.grand_total', 114000);
    }
}
