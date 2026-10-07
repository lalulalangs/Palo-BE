<?php

namespace Tests\Feature\Api\V1;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected Category $parentCategory;

    protected Category $childCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parentCategory = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);

        $this->childCategory = Category::create([
            'parent_id' => $this->parentCategory->id,
            'name' => 'Jaket Waterproof',
            'slug' => 'jaket-waterproof',
        ]);
    }

    public function test_can_get_active_banners(): void
    {
        Banner::create([
            'title' => 'Banner Active 1',
            'image_url' => 'banners/banner1.jpg',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Banner::create([
            'title' => 'Banner Active 2',
            'image_url' => 'banners/banner2.jpg',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Banner::create([
            'title' => 'Banner Inactive',
            'image_url' => 'banners/banner3.jpg',
            'is_active' => false,
            'sort_order' => 3,
        ]);

        $response = $this->getJson('/api/v1/banners');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Daftar banner berhasil diambil',
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Banner Active 1')
            ->assertJsonPath('data.1.title', 'Banner Active 2');
    }

    public function test_can_get_nested_categories(): void
    {
        Product::create([
            'category_id' => $this->childCategory->id,
            'name' => 'Jaket Rinjani X',
            'slug' => 'jaket-rinjani-x',
            'base_price' => 350000.00,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Daftar kategori berhasil diambil',
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'apparel')
            ->assertJsonPath('data.0.children.0.slug', 'jaket-waterproof')
            ->assertJsonPath('data.0.children.0.products_count', 1);
    }

    public function test_can_get_products_list_with_pagination(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            Product::create([
                'category_id' => $this->childCategory->id,
                'name' => "Produk Apparel {$i}",
                'slug' => "produk-apparel-{$i}",
                'base_price' => 100000.00 + ($i * 10000),
                'is_active' => true,
            ]);
        }

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Daftar produk berhasil diambil',
            ])
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('pagination.per_page', 12)
            ->assertJsonPath('pagination.total', 15)
            ->assertJsonPath('pagination.has_more', true);
    }

    public function test_can_filter_products_by_category(): void
    {
        $gearCategory = Category::create([
            'name' => 'Gear',
            'slug' => 'gear',
        ]);

        $productApparel = Product::create([
            'category_id' => $this->childCategory->id,
            'name' => 'Jaket Hujan',
            'slug' => 'jaket-hujan',
            'base_price' => 200000.00,
            'is_active' => true,
        ]);

        $productGear = Product::create([
            'category_id' => $gearCategory->id,
            'name' => 'Headlamp Pro',
            'slug' => 'headlamp-pro',
            'base_price' => 150000.00,
            'is_active' => true,
        ]);

        // Filter by parent category 'apparel' should include product in child category 'jaket-waterproof'
        $response = $this->getJson('/api/v1/products?category=apparel');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productApparel->id);

        // Filter by specific category 'gear'
        $responseGear = $this->getJson('/api/v1/products?category=gear');
        $responseGear->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productGear->id);
    }

    public function test_can_search_and_filter_products(): void
    {
        $p1 = Product::create([
            'category_id' => $this->childCategory->id,
            'name' => 'Celana Gunung Quickdry',
            'slug' => 'celana-gunung-quickdry',
            'description' => 'Celana elastis dan cepat kering',
            'base_price' => 250000.00,
            'is_featured' => true,
            'is_active' => true,
        ]);

        $p2 = Product::create([
            'category_id' => $this->childCategory->id,
            'name' => 'Kaos Katun Rinjani',
            'slug' => 'kaos-katun-rinjani',
            'description' => 'Bahan adem 100% katun',
            'base_price' => 120000.00,
            'is_featured' => false,
            'is_active' => true,
        ]);

        // Search 'quickdry'
        $searchResponse = $this->getJson('/api/v1/products?search=quickdry');
        $searchResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p1->id);

        // Filter is_featured=true
        $featuredResponse = $this->getJson('/api/v1/products?is_featured=1');
        $featuredResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p1->id);

        // Filter price range
        $priceResponse = $this->getJson('/api/v1/products?min_price=200000&max_price=300000');
        $priceResponse->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p1->id);

        // Sorting price_asc
        $sortAsc = $this->getJson('/api/v1/products?sort=price_asc');
        $sortAsc->assertStatus(200)
            ->assertJsonPath('data.0.id', $p2->id)
            ->assertJsonPath('data.1.id', $p1->id);
    }

    public function test_can_filter_products_by_in_stock(): void
    {
        $inStockProduct = Product::create([
            'category_id' => $this->childCategory->id,
            'name' => 'Tenda Tersedia',
            'slug' => 'tenda-tersedia',
            'base_price' => 800000.00,
            'is_active' => true,
        ]);
        $variant1 = ProductVariant::create([
            'product_id' => $inStockProduct->id,
            'name' => 'Warna Hijau',
        ]);
        Sku::create([
            'variant_id' => $variant1->id,
            'sku_code' => 'TND-GRN',
            'stock' => 5,
            'is_active' => true,
        ]);

        $outOfStockProduct = Product::create([
            'category_id' => $this->childCategory->id,
            'name' => 'Tenda Habis',
            'slug' => 'tenda-habis',
            'base_price' => 900000.00,
            'is_active' => true,
        ]);
        $variant2 = ProductVariant::create([
            'product_id' => $outOfStockProduct->id,
            'name' => 'Warna Merah',
        ]);
        Sku::create([
            'variant_id' => $variant2->id,
            'sku_code' => 'TND-RED',
            'stock' => 0,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products?in_stock=1');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inStockProduct->id);
    }

    public function test_can_get_product_detail_with_variants_skus_and_media(): void
    {
        $product = Product::create([
            'category_id' => $this->childCategory->id,
            'name' => 'Palo Rinjani Classic Hoodie',
            'slug' => 'palo-rinjani-classic-hoodie',
            'description' => 'Hoodie hangat katun fleece',
            'base_price' => 350000.00,
            'is_featured' => true,
            'is_active' => true,
        ]);

        ProductMedia::create([
            'product_id' => $product->id,
            'url' => 'products/hoodie-front.jpg',
            'sort_order' => 0,
        ]);

        $variantM = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Black / Size M',
            'attributes' => ['color' => 'Black', 'size' => 'M'],
        ]);

        Sku::create([
            'variant_id' => $variantM->id,
            'sku_code' => 'PR-HD-BLK-M',
            'stock' => 10,
            'price_override' => null,
            'is_active' => true,
        ]);

        $variantXL = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Black / Size XL',
            'attributes' => ['color' => 'Black', 'size' => 'XL'],
        ]);

        Sku::create([
            'variant_id' => $variantXL->id,
            'sku_code' => 'PR-HD-BLK-XL',
            'stock' => 5,
            'price_override' => 375000.00,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/palo-rinjani-classic-hoodie');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Detail produk berhasil diambil',
            ])
            ->assertJsonPath('data.name', 'Palo Rinjani Classic Hoodie')
            ->assertJsonPath('data.total_stock', 15)
            ->assertJsonPath('data.is_out_of_stock', false)
            ->assertJsonPath('data.media.0.sort_order', 0)
            ->assertJsonPath('data.media.0.is_thumbnail', true)
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.attributes.color', 'Black')
            ->assertJsonPath('data.variants.1.skus.0.price', 375000);
    }

    public function test_product_detail_returns_404_for_unknown_or_inactive_product(): void
    {
        $inactive = Product::create([
            'category_id' => $this->childCategory->id,
            'name' => 'Produk Nonaktif',
            'slug' => 'produk-nonaktif',
            'base_price' => 100000.00,
            'is_active' => false,
        ]);

        $response404 = $this->getJson('/api/v1/products/tidak-ada');
        $response404->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Produk tidak ditemukan atau tidak aktif',
            ]);

        $responseInactive = $this->getJson('/api/v1/products/produk-nonaktif');
        $responseInactive->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Produk tidak ditemukan atau tidak aktif',
            ]);
    }
}
