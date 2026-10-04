<?php

namespace Tests\Feature;

use App\Domain\Cart\Models\Cart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestData;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
    }

    public function test_guest_can_view_empty_cart(): void
    {
        $response = $this->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.summary.item_count', 0)
            ->assertJsonPath('data.summary.subtotal', 0);
    }

    public function test_guest_can_add_item_to_cart(): void
    {
        $product = $this->makeProduct('T-Shirt Rinjani', price: 150000, stock: 10);
        $sku = $this->firstSku($product);

        $response = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $sku->getKey(),
            'quantity' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Produk ditambahkan ke keranjang.')
            ->assertJsonPath('data.summary.item_count', 2)
            ->assertJsonPath('data.summary.subtotal', 300000)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_adding_item_with_insufficient_stock_returns_409(): void
    {
        $product = $this->makeProduct('Jaket Senaru', price: 350000, stock: 3);
        $sku = $this->firstSku($product);

        $response = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $sku->getKey(),
            'quantity' => 5,
        ]);

        $response->assertStatus(409)
            ->assertJsonStructure(['message', 'errors' => ['quantity']]);
    }

    public function test_guest_can_update_item_quantity(): void
    {
        $product = $this->makeProduct('Topi Rinjani', price: 75000, stock: 10);
        $sku = $this->firstSku($product);

        // Add 1
        $addResponse = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $sku->getKey(),
            'quantity' => 1,
        ]);
        $addResponse->assertCreated();
        $cookies = $this->extractCookies($addResponse);

        $response = $this->withUnencryptedCookies($cookies)->patchJson('/api/v1/cart/items/'.$sku->getKey(), [
            'quantity' => 4,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.item_count', 4)
            ->assertJsonPath('data.summary.subtotal', 300000);
    }

    public function test_updating_item_quantity_to_zero_removes_item(): void
    {
        $product = $this->makeProduct('Bandana Rinjani', price: 45000, stock: 5);
        $sku = $this->firstSku($product);

        $addResponse = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $sku->getKey(),
            'quantity' => 2,
        ]);
        $addResponse->assertCreated();
        $cookies = $this->extractCookies($addResponse);

        $response = $this->withUnencryptedCookies($cookies)->patchJson('/api/v1/cart/items/'.$sku->getKey(), [
            'quantity' => 0,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.item_count', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_guest_can_remove_item_from_cart(): void
    {
        $product = $this->makeProduct('Gantungan Kunci', price: 25000, stock: 10);
        $sku = $this->firstSku($product);

        $addResponse = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $sku->getKey(),
            'quantity' => 1,
        ]);
        $addResponse->assertCreated();
        $cookies = $this->extractCookies($addResponse);

        $response = $this->withUnencryptedCookies($cookies)->deleteJson('/api/v1/cart/items/'.$sku->getKey());

        $response->assertOk()
            ->assertJsonPath('message', 'Produk dikeluarkan dari keranjang.')
            ->assertJsonPath('data.summary.item_count', 0);
    }

    public function test_guest_cart_merges_into_customer_cart_on_login(): void
    {
        $user = $this->makeCustomer([
            'email' => 'buyer@rinjani.test',
            'password' => 'Rahasia#2026',
        ]);

        $productA = $this->makeProduct('Kaos Pendaki', price: 100000, stock: 10);
        $skuA = $this->firstSku($productA);

        $productB = $this->makeProduct('Stiker Rinjani', price: 20000, stock: 50);
        $skuB = $this->firstSku($productB);

        // Pre-existing user cart with 1 of item A
        $userCart = Cart::create(['user_id' => $user->getKey()]);
        $userCart->items()->create([
            'sku_id' => $skuA->getKey(),
            'quantity' => 1,
            'price_snapshot' => $skuA->price,
        ]);

        // Guest adds 2 of item A
        $addA = $this->postJson('/api/v1/cart/items', [
            'sku_id' => $skuA->getKey(),
            'quantity' => 2,
        ]);
        $addA->assertCreated();
        $cookies = $this->extractCookies($addA);

        // Guest adds 3 of item B using same session
        $addB = $this->withUnencryptedCookies($cookies)->postJson('/api/v1/cart/items', [
            'sku_id' => $skuB->getKey(),
            'quantity' => 3,
        ]);
        $addB->assertCreated();
        $cookies = $this->extractCookies($addB);

        // Login as customer with same guest session cookies
        $loginResponse = $this->withUnencryptedCookies($cookies)->postJson('/api/v1/auth/login', [
            'email' => 'buyer@rinjani.test',
            'password' => 'Rahasia#2026',
        ]);

        $loginResponse->assertOk();
        $token = $loginResponse->json('data.token');

        // Customer's cart should now have combined quantities:
        // SKU A: 1 (existing) + 2 (guest) = 3
        // SKU B: 3
        $this->lupakanGuard();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('data.summary.item_count', 6)
            ->assertJsonCount(2, 'data.items');
    }

    /**
     * @return array<string, string>
     */
    private function extractCookies($response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }
}
