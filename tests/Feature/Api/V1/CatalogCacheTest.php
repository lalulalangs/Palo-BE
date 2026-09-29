<?php

namespace Tests\Feature\Api\V1;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->category = Category::create([
            'name' => 'Hiking Gear',
            'slug' => 'hiking-gear',
        ]);
    }

    public function test_banners_are_cached_and_invalidated_on_banner_mutation(): void
    {
        $banner = Banner::create([
            'title' => 'Initial Banner',
            'image_url' => 'banners/init.jpg',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // First request populates cache
        $res1 = $this->getJson('/api/v1/banners');
        $res1->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Initial Banner');

        // Update banner via Eloquent model (triggers BannerObserver)
        $banner->update([
            'title' => 'Updated Banner Title',
        ]);

        // Second request must reflect updated banner immediately without stale cache
        $res2 = $this->getJson('/api/v1/banners');
        $res2->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Updated Banner Title');
    }

    public function test_categories_are_cached_and_invalidated_on_category_mutation(): void
    {
        // First request populates category cache
        $res1 = $this->getJson('/api/v1/categories');
        $res1->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Hiking Gear');

        // Update category name
        $this->category->update([
            'name' => 'Mountain & Hiking Gear',
        ]);

        // Second request must reflect updated name immediately
        $res2 = $this->getJson('/api/v1/categories');
        $res2->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Mountain & Hiking Gear');
    }

    public function test_product_detail_is_cached_and_invalidated_on_product_update(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Rinjani Tent 2P',
            'slug' => 'rinjani-tent-2p',
            'base_price' => 1200000.00,
            'is_active' => true,
        ]);

        // Request 1: populates cache
        $res1 = $this->getJson('/api/v1/products/rinjani-tent-2p');
        $res1->assertStatus(200)
            ->assertJsonPath('data.name', 'Rinjani Tent 2P');

        // Mutate product
        $product->update([
            'name' => 'Rinjani Ultralight Tent 2P',
        ]);

        // Request 2: must get new name
        $res2 = $this->getJson('/api/v1/products/rinjani-tent-2p');
        $res2->assertStatus(200)
            ->assertJsonPath('data.name', 'Rinjani Ultralight Tent 2P');
    }

    /**
     * PRD §3.2 Acceptance Criteria:
     * Given Admin mengubah harga sebuah varian, When buyer refresh halaman produk,
     * Then harga baru tampil tanpa cache lama (cache-busting/invalidation).
     */
    public function test_product_detail_cache_is_invalidated_when_variant_price_changes(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Palo Trekking Pole',
            'slug' => 'palo-trekking-pole',
            'base_price' => 250000.00,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Carbon Fiber / Red',
            'attributes' => ['material' => 'Carbon', 'color' => 'Red'],
        ]);

        $sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PR-TP-CRB-RED',
            'stock' => 10,
            'price_override' => 250000.00,
            'is_active' => true,
        ]);

        // Buyer loads product page
        $res1 = $this->getJson('/api/v1/products/palo-trekking-pole');
        $res1->assertStatus(200)
            ->assertJsonPath('data.variants.0.skus.0.price', 250000);

        // Admin updates SKU price override (e.g. from Filament panel)
        $sku->update([
            'price_override' => 320000.00,
        ]);

        // Buyer refreshes product page
        $res2 = $this->getJson('/api/v1/products/palo-trekking-pole');
        $res2->assertStatus(200)
            ->assertJsonPath('data.variants.0.skus.0.price', 320000);
    }

    public function test_product_detail_cache_is_invalidated_when_sku_stock_depleted(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Palo Headlamp',
            'slug' => 'palo-headlamp',
            'base_price' => 150000.00,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Standard',
        ]);

        $sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PR-HL-STD',
            'stock' => 5,
            'is_active' => true,
        ]);

        $res1 = $this->getJson('/api/v1/products/palo-headlamp');
        $res1->assertStatus(200)
            ->assertJsonPath('data.total_stock', 5)
            ->assertJsonPath('data.is_out_of_stock', false);

        // Stock goes to zero
        $sku->update([
            'stock' => 0,
        ]);

        $res2 = $this->getJson('/api/v1/products/palo-headlamp');
        $res2->assertStatus(200)
            ->assertJsonPath('data.total_stock', 0)
            ->assertJsonPath('data.is_out_of_stock', true);
    }

    public function test_product_list_cache_is_invalidated_when_new_product_is_created(): void
    {
        Product::create([
            'category_id' => $this->category->id,
            'name' => 'Product Existing',
            'slug' => 'product-existing',
            'base_price' => 100000.00,
            'is_active' => true,
        ]);

        // Request 1
        $res1 = $this->getJson('/api/v1/products');
        $res1->assertStatus(200)
            ->assertJsonPath('pagination.total', 1);

        // Add new product
        Product::create([
            'category_id' => $this->category->id,
            'name' => 'Product Brand New',
            'slug' => 'product-brand-new',
            'base_price' => 200000.00,
            'is_active' => true,
        ]);

        // Request 2: listing version bumped, new total immediately visible
        $res2 = $this->getJson('/api/v1/products');
        $res2->assertStatus(200)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_product_detail_cache_is_invalidated_when_media_is_added(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Gaiters Waterproof',
            'slug' => 'gaiters-waterproof',
            'base_price' => 75000.00,
            'is_active' => true,
        ]);

        $res1 = $this->getJson('/api/v1/products/gaiters-waterproof');
        $res1->assertStatus(200)
            ->assertJsonCount(0, 'data.media');

        // Add media gallery item
        ProductMedia::create([
            'product_id' => $product->id,
            'url' => 'products/gaiters-front.jpg',
            'sort_order' => 0,
            'is_thumbnail' => true,
        ]);

        $res2 = $this->getJson('/api/v1/products/gaiters-waterproof');
        $res2->assertStatus(200)
            ->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.media.0.is_thumbnail', true);
    }
}
