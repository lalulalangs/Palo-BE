<?php

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Sku;
use App\Services\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ImportCatalogProductsTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tempDir = sys_get_temp_dir() . '/catalog_import_test_' . Str::random(10);
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_it_imports_products_from_valid_folders(): void
    {
        // Siapkan struktur folder 1 & 2
        $this->createMockProductFolder(1, [
            'product_name' => 'PALO T-SHIRT — ROUTE TO HEAVEN BLACK',
            'color' => 'Black',
            'details' => [
                'description' => 'Inspired by Mount Rinjani, designed for everyday wear.',
                'material' => 'Cotton Combed 32s',
                'weight' => '180 GSM',
                'fit' => 'Oversized Boxy Fit',
                'finish' => 'Stone Wash',
            ],
            'price' => 199000,
            'available_sizes' => ['S', 'M', 'L', 'XL'],
        ], ['tampak-depan.jpg', 'tampak-belakang.jpg']);

        $this->createMockProductFolder(2, [
            'product_name' => 'PALO T-SHIRT — ROUTE TO HEAVEN WHITE',
            'color' => 'White',
            'details' => [
                'description' => 'White t-shirt description.',
                'material' => 'Cotton Combed 32s',
                'weight' => '180 GSM',
                'fit' => 'Oversized Boxy Fit',
                'finish' => 'Bio Wash',
            ],
            'price' => 170000,
            'available_sizes' => ['S', 'M', 'L', 'XL'],
        ], ['depan.png', 'detail.png']);

        // Mock ImageOptimizer agar tidak perlu eksekusi GD decode asli di test
        $mockOptimizer = Mockery::mock(ImageOptimizer::class);
        $mockOptimizer->shouldReceive('optimize')
            ->andReturnUsing(function (UploadedFile $file, string $dir, string $disk) {
                $fakePath = "{$dir}/" . Str::ulid() . '.webp';
                Storage::disk($disk)->put($fakePath, 'fake-webp-content');
                return $fakePath;
            });
        $this->app->instance(ImageOptimizer::class, $mockOptimizer);

        Cache::put('catalog:categories', 'cached-data', 60);

        $exitCode = $this->artisan('catalog:import', [
            'path' => $this->tempDir,
            '--stock' => 30,
        ])->assertSuccessful()->run();

        $this->assertEquals(0, $exitCode);

        // Verifikasi kategori
        $category = Category::where('slug', 'apparel')->first();
        $this->assertNotNull($category);
        $this->assertEquals('Apparel', $category->name);

        // Verifikasi produk
        $this->assertDatabaseCount('products', 2);
        $product1 = Product::where('slug', 'palo-t-shirt-route-to-heaven-black')->first();
        $this->assertNotNull($product1);
        $this->assertEquals(199000.00, (float) $product1->base_price);
        $this->assertTrue($product1->is_active);
        $this->assertFalse($product1->is_featured);

        // Verifikasi format deskripsi dengan bullet points
        $expectedDesc = "Inspired by Mount Rinjani, designed for everyday wear.\n\n"
            . "• Material: Cotton Combed 32s\n"
            . "• Weight: 180 GSM\n"
            . "• Fit: Oversized Boxy Fit\n"
            . "• Finish: Stone Wash";
        $this->assertEquals($expectedDesc, $product1->description);

        // Verifikasi varian & SKU
        $this->assertCount(4, $product1->variants);
        $this->assertCount(4, $product1->skus);

        $skuS = $product1->skus->firstWhere('sku_code', 'PR-PALO-T-SHIRT-ROUTE-TO-HEAVEN-BLACK-S');
        $this->assertNotNull($skuS);
        $this->assertEquals(30, $skuS->stock);
        $this->assertEquals(350, $skuS->weight_grams);
        $this->assertTrue($skuS->is_active);

        // Verifikasi urutan media & thumbnail
        $this->assertCount(2, $product1->media);
        $thumbnail = $product1->thumbnail;
        $this->assertNotNull($thumbnail);
        $this->assertEquals(1, $thumbnail->sort_order);

        $secondMedia = $product1->media->where('sort_order', 2)->first();
        $this->assertNotNull($secondMedia);

        // Verifikasi cache categories dibersihkan
        $this->assertFalse(Cache::has('catalog:categories'));
    }

    public function test_it_skips_existing_product_without_force(): void
    {
        $this->createMockProductFolder(1, [
            'product_name' => 'PALO T-SHIRT — ROUTE TO HEAVEN BLACK',
            'color' => 'Black',
            'details' => ['description' => 'Original description'],
            'price' => 199000,
            'available_sizes' => ['S'],
        ]);

        $mockOptimizer = Mockery::mock(ImageOptimizer::class);
        $this->app->instance(ImageOptimizer::class, $mockOptimizer);

        // Run pertama kali
        $this->artisan('catalog:import', ['path' => $this->tempDir])->assertSuccessful();
        $this->assertDatabaseCount('products', 1);

        // Run kedua kali tanpa force
        $this->artisan('catalog:import', ['path' => $this->tempDir])
            ->expectsOutputToContain('sudah ada. Dilewati')
            ->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
    }

    public function test_it_overwrites_existing_product_with_force(): void
    {
        $this->createMockProductFolder(1, [
            'product_name' => 'PALO T-SHIRT — ROUTE TO HEAVEN BLACK',
            'color' => 'Black',
            'details' => ['description' => 'First description'],
            'price' => 199000,
            'available_sizes' => ['S'],
        ]);

        $mockOptimizer = Mockery::mock(ImageOptimizer::class);
        $mockOptimizer->shouldReceive('optimize')->andReturn('products/dummy.webp');
        $this->app->instance(ImageOptimizer::class, $mockOptimizer);

        $this->artisan('catalog:import', ['path' => $this->tempDir])->assertSuccessful();

        // Update data.json
        $this->createMockProductFolder(1, [
            'product_name' => 'PALO T-SHIRT — ROUTE TO HEAVEN BLACK',
            'color' => 'Black',
            'details' => ['description' => 'Updated description with force'],
            'price' => 250000,
            'available_sizes' => ['S', 'M'],
        ]);

        $this->artisan('catalog:import', [
            'path' => $this->tempDir,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
        $product = Product::first();
        $this->assertEquals(250000.00, (float) $product->base_price);
        $this->assertEquals('Updated description with force', $product->description);
        $this->assertCount(2, $product->skus);
    }

    private function createMockProductFolder(int|string $folderName, array $jsonData, array $images = []): void
    {
        $folderPath = $this->tempDir . '/' . $folderName;
        File::ensureDirectoryExists($folderPath);
        File::put($folderPath . '/data.json', json_encode($jsonData, JSON_PRETTY_PRINT));

        foreach ($images as $imageName) {
            File::put($folderPath . '/' . $imageName, 'dummy image content');
        }
    }
}
