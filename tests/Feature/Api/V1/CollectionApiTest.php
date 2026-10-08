<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionApiTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);
    }

    public function test_can_get_active_collections_list(): void
    {
        $active1 = Collection::create([
            'name' => 'DECADE (10th Anniversary)',
            'slug' => 'decade',
            'tagline' => 'A Decade of Rugged Heritage',
            'badge_label' => 'Limited Drop',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $active2 = Collection::create([
            'name' => 'Monsoon Rinjani 2026',
            'slug' => 'monsoon-rinjani-2026',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $inactive = Collection::create([
            'name' => 'Draft Collection',
            'slug' => 'draft-collection',
            'is_active' => false,
            'sort_order' => 3,
        ]);

        $response = $this->getJson('/api/v1/collections');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Daftar koleksi berhasil diambil',
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'decade')
            ->assertJsonPath('data.0.badge_label', 'Limited Drop')
            ->assertJsonPath('data.0.status_label', 'Aktif')
            ->assertJsonPath('data.1.slug', 'monsoon-rinjani-2026');
    }

    public function test_can_filter_featured_collections(): void
    {
        Collection::create([
            'name' => 'Featured Collection',
            'slug' => 'featured-collection',
            'is_active' => true,
            'is_featured' => true,
            'sort_order' => 1,
        ]);

        Collection::create([
            'name' => 'Regular Collection',
            'slug' => 'regular-collection',
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/v1/collections?featured=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'featured-collection');
    }

    public function test_active_collections_count_only_active_products(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE',
            'slug' => 'decade',
            'is_active' => true,
        ]);

        $activeProduct = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Active Shirt',
            'slug' => 'active-shirt',
            'base_price' => 150000,
            'is_active' => true,
        ]);

        $inactiveProduct = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Draft Shirt',
            'slug' => 'draft-shirt',
            'base_price' => 120000,
            'is_active' => false,
        ]);

        $collection->products()->attach($activeProduct->id, ['sort_order' => 1]);
        $collection->products()->attach($inactiveProduct->id, ['sort_order' => 2]);

        $response = $this->getJson('/api/v1/collections');

        $response->assertOk()
            ->assertJsonPath('data.0.products_count', 1);
    }

    public function test_can_get_collection_detail_with_curated_products_in_order(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE (10th Anniversary)',
            'slug' => 'decade',
            'tagline' => 'A Decade of Rugged Heritage',
            'description' => 'Edisi spesial 10 tahun PaloRinjani.',
            'badge_label' => 'Special Drop',
            'is_active' => true,
        ]);

        $productA = Product::create([
            'category_id' => $this->category->id,
            'name' => 'DECADE Cap',
            'slug' => 'decade-cap',
            'base_price' => 95000,
            'is_active' => true,
        ]);

        $productB = Product::create([
            'category_id' => $this->category->id,
            'name' => 'DECADE Hero Jacket',
            'slug' => 'decade-hero-jacket',
            'base_price' => 450000,
            'is_active' => true,
        ]);

        $productC = Product::create([
            'category_id' => $this->category->id,
            'name' => 'DECADE Cargo Pants',
            'slug' => 'decade-cargo-pants',
            'base_price' => 280000,
            'is_active' => true,
        ]);

        // Curated order: B (1), C (2), A (3)
        $collection->products()->attach($productB->id, ['sort_order' => 1]);
        $collection->products()->attach($productC->id, ['sort_order' => 2]);
        $collection->products()->attach($productA->id, ['sort_order' => 3]);

        $response = $this->getJson('/api/v1/collections/decade');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Detail koleksi berhasil diambil',
            ])
            ->assertJsonPath('data.name', 'DECADE (10th Anniversary)')
            ->assertJsonPath('data.badge_label', 'Special Drop')
            ->assertJsonCount(3, 'data.products')
            ->assertJsonPath('data.products.0.slug', 'decade-hero-jacket')
            ->assertJsonPath('data.products.1.slug', 'decade-cargo-pants')
            ->assertJsonPath('data.products.2.slug', 'decade-cap');
    }

    public function test_inactive_products_in_collection_are_excluded(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE',
            'slug' => 'decade',
            'is_active' => true,
        ]);

        $activeProduct = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Active Shirt',
            'slug' => 'active-shirt',
            'base_price' => 150000,
            'is_active' => true,
        ]);

        $inactiveProduct = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Inactive Pants',
            'slug' => 'inactive-pants',
            'base_price' => 200000,
            'is_active' => false,
        ]);

        $collection->products()->attach($activeProduct->id, ['sort_order' => 1]);
        $collection->products()->attach($inactiveProduct->id, ['sort_order' => 2]);

        $response = $this->getJson('/api/v1/collections/decade');

        $response->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.slug', 'active-shirt');
    }

    public function test_out_of_stock_products_remain_visible_with_flag(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE',
            'slug' => 'decade',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Sold Out Shirt',
            'slug' => 'sold-out-shirt',
            'base_price' => 150000,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'M',
        ]);

        Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'DEC-SHIRT-M',
            'stock' => 0,
            'is_active' => true,
        ]);

        $collection->products()->attach($product->id, ['sort_order' => 1]);

        $response = $this->getJson('/api/v1/collections/decade');

        $response->assertOk()
            ->assertJsonPath('data.products.0.is_out_of_stock', true);
    }

    public function test_cannot_get_inactive_or_expired_collection(): void
    {
        Collection::create([
            'name' => 'Inactive Collection',
            'slug' => 'inactive-coll',
            'is_active' => false,
        ]);

        Collection::create([
            'name' => 'Expired Collection',
            'slug' => 'expired-coll',
            'is_active' => true,
            'ended_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/collections/inactive-coll')->assertNotFound();
        $this->getJson('/api/v1/collections/expired-coll')->assertNotFound();
    }

    public function test_can_filter_products_by_collection_slug(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE',
            'slug' => 'decade',
            'is_active' => true,
        ]);

        $productInCollection = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Decade Shirt',
            'slug' => 'decade-shirt',
            'base_price' => 150000,
            'is_active' => true,
        ]);

        $otherProduct = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Regular Shirt',
            'slug' => 'regular-shirt',
            'base_price' => 120000,
            'is_active' => true,
        ]);

        $collection->products()->attach($productInCollection->id);

        $response = $this->getJson('/api/v1/products?collection=decade');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'decade-shirt');
    }

    public function test_product_detail_and_list_include_associated_collections(): void
    {
        $collection = Collection::create([
            'name' => 'DECADE',
            'slug' => 'decade',
            'badge_label' => '10 Years',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'DECADE Cap',
            'slug' => 'decade-cap',
            'base_price' => 95000,
            'is_active' => true,
        ]);

        $collection->products()->attach($product->id);

        // Check product detail
        $showResponse = $this->getJson('/api/v1/products/decade-cap');
        $showResponse->assertOk()
            ->assertJsonPath('data.collections.0.slug', 'decade')
            ->assertJsonPath('data.collections.0.badge_label', '10 Years');

        // Check product list
        $listResponse = $this->getJson('/api/v1/products');
        $listResponse->assertOk()
            ->assertJsonPath('data.0.collections.0.slug', 'decade');
    }
}
