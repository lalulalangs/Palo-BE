<?php

namespace Tests\Feature\Console;

use App\Models\Address;
use App\Models\AdminUser;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetStoreCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_store_catalog_can_be_cancelled_interactively(): void
    {
        Category::create(['name' => 'Outdoor Gear', 'slug' => 'outdoor-gear']);

        $this->artisan('store:reset-catalog')
            ->expectsConfirmation('PERINGATAN: Perintah ini akan menghapus semua produk, pesanan, stok, koleksi, voucher, dan akun pembeli di database. Lanjutkan?', 'no')
            ->expectsOutput('Operasi dibatalkan oleh pengguna.')
            ->assertSuccessful();

        $this->assertDatabaseHas('categories', ['slug' => 'outdoor-gear']);
    }

    public function test_reset_store_catalog_purges_catalog_and_orders_while_preserving_apparel(): void
    {
        // 1. Setup master data that must be preserved
        $admin = AdminUser::factory()->create(['email' => 'superadmin@example.com']);
        AppSetting::setValue('store_name', 'Palo Rinjani');

        // 2. Setup categories
        $apparel = Category::create(['name' => 'Apparel', 'slug' => 'apparel']);
        $outdoor = Category::create(['name' => 'Outdoor Gear', 'slug' => 'outdoor-gear']);

        // 3. Setup catalog data
        $product = Product::create([
            'category_id' => $outdoor->id,
            'name' => 'Tenda Dome 4P',
            'slug' => 'tenda-dome-4p',
            'base_price' => 1200000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Orange',
            'attributes' => ['color' => 'Orange'],
        ]);

        $sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'TND-DOME-ORG',
            'stock' => 10,
        ]);

        ProductMedia::create([
            'product_id' => $product->id,
            'url' => 'products/tenda.jpg',
            'type' => 'image',
            'sort_order' => 1,
        ]);

        $collection = Collection::create(['name' => 'Summer Camp', 'slug' => 'summer-camp']);
        $collection->products()->attach($product->id, ['sort_order' => 1]);

        StockMovement::create([
            'sku_id' => $sku->id,
            'type' => 'in',
            'quantity_change' => 10,
            'stock_before' => 0,
            'stock_after' => 10,
            'reference_type' => 'manual',
            'user_id' => $admin->id,
        ]);

        // 4. Setup buyer, address, voucher, and order data
        $buyer = User::factory()->create();
        $buyer->createToken('customer-auth-token');

        Address::create([
            'user_id' => $buyer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Budi',
            'phone' => '08123456789',
            'province' => 'NTB',
            'city' => 'Mataram',
            'district' => 'Ampenan',
            'postal_code' => '83125',
            'full_address' => 'Jl. Rinjani No. 1',
        ]);

        $voucher = Voucher::create([
            'code' => 'DISC10',
            'type' => 'fixed',
            'value' => 10000,
            'is_active' => true,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-TEST-001',
            'user_id' => $buyer->id,
            'status' => 'pending',
            'total_amount' => 1190000,
            'voucher_id' => $voucher->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $sku->id,
            'product_name_snapshot' => 'Tenda Dome 4P',
            'variant_name_snapshot' => 'Orange',
            'sku_code_snapshot' => 'TND-DOME-ORG',
            'price_snapshot' => 1200000,
            'quantity' => 1,
            'subtotal' => 1200000,
        ]);

        // Execute command
        $this->artisan('store:reset-catalog --force')
            ->expectsOutput('Pembersihan database berhasil diselesaikan.')
            ->assertSuccessful();

        // Assert preserved data
        $this->assertDatabaseHas('admin_users', ['id' => $admin->id]);
        $this->assertDatabaseHas('categories', ['slug' => 'apparel', 'id' => $apparel->id]);
        $this->assertEquals('Palo Rinjani', AppSetting::getValue('store_name'));

        // Assert purged data
        $this->assertDatabaseMissing('categories', ['slug' => 'outdoor-gear']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('skus', 0);
        $this->assertDatabaseCount('product_media', 0);
        $this->assertDatabaseCount('collections', 0);
        $this->assertDatabaseCount('collection_product', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('addresses', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reset_store_catalog_can_purge_dummy_admins_when_flag_is_passed(): void
    {
        $superAdmin = AdminUser::factory()->superAdmin()->create(['email' => 'admin@palorinjani.local']);
        $dummyAdmin1 = AdminUser::factory()->create(['email' => 'dummy1@example.com']);
        $dummyAdmin2 = AdminUser::factory()->create(['email' => 'dummy2@example.com']);

        $this->artisan('store:reset-catalog --force --reset-admins')
            ->assertSuccessful();

        $this->assertDatabaseHas('admin_users', ['id' => $superAdmin->id]);
        $this->assertDatabaseMissing('admin_users', ['id' => $dummyAdmin1->id]);
        $this->assertDatabaseMissing('admin_users', ['id' => $dummyAdmin2->id]);
        $this->assertDatabaseCount('admin_users', 1);
    }
}
