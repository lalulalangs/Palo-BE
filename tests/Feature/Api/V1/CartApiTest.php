<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Product $product;

    protected ProductVariant $variant;

    protected Sku $sku;

    protected Sku $sku2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Pakaian Gunung',
            'slug' => 'pakaian-gunung',
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Kaos Dryfit Senaru Summit',
            'slug' => 'kaos-dryfit-senaru-summit',
            'base_price' => 149000,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Hitam / M',
            'attributes' => ['Warna' => 'Hitam', 'Ukuran' => 'M'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'KDS-HIT-M',
            'price_override' => null,
            'stock' => 10,
            'is_active' => true,
        ]);

        $this->sku2 = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'KDS-HIT-L',
            'price_override' => 159000,
            'stock' => 5,
            'is_active' => true,
        ]);
    }

    public function test_guest_can_view_empty_cart(): void
    {
        $response = $this->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.summary.item_count', 0)
            ->assertJsonPath('data.summary.total_quantity', 0)
            ->assertJsonPath('data.summary.subtotal', 0)
            ->assertCookie('guest_cart_id');
    }

    public function test_guest_can_add_item_to_cart_and_receives_guest_token_cookie(): void
    {
        $response = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $this->sku->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.sku_id', $this->sku->id)
            ->assertJsonPath('data.items.0.product_name', 'Kaos Dryfit Senaru Summit')
            ->assertJsonPath('data.items.0.sku_code', 'KDS-HIT-M')
            ->assertJsonPath('data.items.0.unit_price', 149000)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.subtotal', 298000)
            ->assertJsonPath('data.summary.item_count', 1)
            ->assertJsonPath('data.summary.total_quantity', 2)
            ->assertJsonPath('data.summary.subtotal', 298000)
            ->assertCookie('guest_cart_id');
    }

    public function test_guest_can_retrieve_cart_with_items_using_guest_token_header(): void
    {
        $guestToken = 'test-guest-token-12345';

        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        $response = $this->withHeader('X-Guest-Token', $guestToken)
            ->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.sku_id', $this->sku->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.summary.subtotal', 298000);
    }

    public function test_guest_cannot_add_item_exceeding_available_stock(): void
    {
        $response = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $this->sku->id,
            'quantity' => 15, // Stock is 10
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_guest_can_update_item_quantity(): void
    {
        $guestToken = 'guest-update-token';

        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        $updateResponse = $this->withHeader('X-Guest-Token', $guestToken)
            ->patchJson("/api/v1/cart/items/{$this->sku->id}", [
                'quantity' => 5,
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.summary.subtotal', 149000 * 5);
    }

    public function test_guest_can_remove_item_from_cart(): void
    {
        $guestToken = 'guest-remove-token';

        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        $deleteResponse = $this->withHeader('X-Guest-Token', $guestToken)
            ->deleteJson("/api/v1/cart/items/{$this->sku->id}");

        $deleteResponse->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.summary.item_count', 0);
    }

    public function test_authenticated_user_can_manage_database_cart(): void
    {
        /** @var User $user */
        $user = User::create([
            'name' => 'Ahmad Rinjani',
            'email' => 'ahmad@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku2->id,
                'quantity' => 2,
            ])->assertOk()
            ->assertJsonPath('data.items.0.sku_id', $this->sku2->id)
            ->assertJsonPath('data.items.0.unit_price', 159000)
            ->assertJsonPath('data.summary.subtotal', 318000);

        // Verify saved in PostgreSQL database
        $this->assertDatabaseHas('carts', [
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('cart_items', [
            'sku_id' => $this->sku2->id,
            'quantity' => 2,
        ]);
    }

    public function test_guest_cart_is_merged_into_user_cart_via_explicit_merge_endpoint(): void
    {
        $guestToken = 'guest-merge-token';

        // Guest adds SKU 1 (qty 2)
        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        /** @var User $user */
        $user = User::create([
            'name' => 'Lalu Customer',
            'email' => 'lalu@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        // User already has SKU 2 (qty 1) in their account cart
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku2->id,
                'quantity' => 1,
            ])->assertOk();

        // Merge guest cart
        $mergeResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cart/merge', [
                'guest_token' => $guestToken,
            ]);

        $mergeResponse->assertOk()
            ->assertJsonPath('data.summary.item_count', 2)
            ->assertJsonPath('data.summary.total_quantity', 3);

        // Database now contains both items
        $this->assertDatabaseHas('cart_items', ['sku_id' => $this->sku->id, 'quantity' => 2]);
        $this->assertDatabaseHas('cart_items', ['sku_id' => $this->sku2->id, 'quantity' => 1]);
    }

    public function test_guest_cart_is_automatically_merged_upon_customer_login(): void
    {
        $guestToken = 'guest-login-merge-token';

        // Guest adds SKU 1 (qty 3)
        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 3,
            ])->assertOk();

        $user = User::create([
            'name' => 'User Login Merge',
            'email' => 'loginmerge@example.test',
            'password_hash' => Hash::make('Secret123!'),
        ]);

        // Customer logs in and provides the guest token
        $loginResponse = $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/auth/login', [
                'email' => 'loginmerge@example.test',
                'password' => 'Secret123!',
                'guest_token' => $guestToken,
            ]);

        $loginResponse->assertOk();

        // Check user cart in database
        $this->assertDatabaseHas('cart_items', [
            'sku_id' => $this->sku->id,
            'quantity' => 3,
        ]);
    }

    public function test_guest_cart_is_automatically_merged_upon_customer_registration(): void
    {
        $guestToken = 'guest-register-merge-token';

        // Guest adds SKU 1 (qty 2)
        $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        // Customer registers new account
        $registerResponse = $this->withHeader('X-Guest-Token', $guestToken)
            ->postJson('/api/v1/auth/register', [
                'name' => 'New Customer Registrant',
                'email' => 'newcustomer@example.test',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'guest_token' => $guestToken,
            ]);

        $registerResponse->assertCreated();

        $registeredUser = User::where('email', 'newcustomer@example.test')->firstOrFail();

        $this->assertDatabaseHas('cart_items', [
            'sku_id' => $this->sku->id,
            'quantity' => 2,
        ]);
    }

    public function test_soft_locked_stock_from_pending_order_reduces_available_stock_in_cart(): void
    {
        /** @var User $otherUser */
        $otherUser = User::create([
            'name' => 'Other Buyer',
            'email' => 'other@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        // Create an active pending order that holds 7 units of SKU (physical stock is 10)
        $order = Order::create([
            'order_number' => 'UJN-20261011-99999',
            'user_id' => $otherUser->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 149000 * 7,
            'total' => 149000 * 7,
            'stock_locked_until' => now()->addMinutes(15),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Kaos Dryfit Senaru Summit',
            'variant_name_snapshot' => 'Hitam / M',
            'sku_code_snapshot' => 'KDS-HIT-M',
            'price_snapshot' => 149000,
            'quantity' => 7,
            'subtotal' => 149000 * 7,
        ]);

        // Physical stock = 10, soft lock = 7. Real available stock = 3.
        $this->assertEquals(3, $this->sku->available_stock);

        // Guest attempts to add 4 units -> must fail
        $response = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $this->sku->id,
            'quantity' => 4,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        // Guest adds 3 units -> must succeed
        $responseValid = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $this->sku->id,
            'quantity' => 3,
        ]);

        $responseValid->assertOk()
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.available_stock', 3);
    }
}
