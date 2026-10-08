<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductMediaManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);

        $this->category = Category::create([
            'name' => 'Outdoor Gear',
            'slug' => 'outdoor-gear',
        ]);
    }

    public function test_can_render_product_list_page_with_thumbnail_column(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Tenda Dome 4P',
            'slug' => 'tenda-dome-4p',
            'base_price' => 1200000.00,
            'is_featured' => true,
            'is_active' => true,
        ]);

        ProductMedia::create([
            'product_id' => $product->id,
            'url' => 'products/tenda-front.jpg',
            'type' => 'image',
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$product]);
    }

    public function test_can_render_create_product_page_with_media_gallery(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateProduct::class)
            ->assertSuccessful()
            ->assertFormFieldExists('media');
    }

    public function test_thumbnail_relationship_returns_lowest_sort_order_image(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Carrier 60L',
            'slug' => 'carrier-60l',
            'base_price' => 850000.00,
            'is_featured' => false,
            'is_active' => true,
        ]);

        $mediaSecondary = ProductMedia::create([
            'product_id' => $product->id,
            'url' => 'products/carrier-side.jpg',
            'type' => 'image',
            'sort_order' => 1,
        ]);

        $mediaPrimary = ProductMedia::create([
            'product_id' => $product->id,
            'url' => 'products/carrier-front.jpg',
            'type' => 'image',
            'sort_order' => 0,
        ]);

        $this->assertEquals($mediaPrimary->id, $product->fresh()->thumbnail->id);
        $this->assertEquals('products/carrier-front.jpg', $product->fresh()->thumbnail->url);
        $this->assertCount(2, $product->fresh()->media);
        $this->assertEquals($mediaPrimary->id, $product->fresh()->media->first()->id);
    }

    public function test_can_upload_and_save_product_with_single_media(): void
    {
        Storage::fake('public');

        $file1 = UploadedFile::fake()->image('photo1.jpg');

        Livewire::actingAs($this->admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->category->id,
                'name' => 'Matras Angin Ultralight',
                'slug' => 'matras-angin-ultralight',
                'base_price' => 250000.00,
                'is_featured' => true,
                'is_active' => true,
                'media' => [
                    [
                        'url' => [$file1],
                        'type' => 'image',
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('slug', 'matras-angin-ultralight')->first();
        $this->assertNotNull($product);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Matras Angin Ultralight',
        ]);

        $this->assertCount(1, $product->media);
        $this->assertNotNull($product->thumbnail);
        $this->assertStringEndsWith('.webp', $product->media->first()->url);
    }

    public function test_cannot_upload_more_than_one_media_item(): void
    {
        Storage::fake('public');

        $file1 = UploadedFile::fake()->image('photo1.jpg');
        $file2 = UploadedFile::fake()->image('photo2.png');

        Livewire::actingAs($this->admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->category->id,
                'name' => 'Tenda Dome 2P',
                'slug' => 'tenda-dome-2p',
                'base_price' => 500000.00,
                'media' => [
                    [
                        'url' => [$file1],
                        'type' => 'image',
                    ],
                    [
                        'url' => [$file2],
                        'type' => 'image',
                    ],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['media']);
    }

    public function test_deleting_product_cascades_to_media(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Kompor Portable',
            'slug' => 'kompor-portable',
            'base_price' => 175000.00,
            'is_active' => true,
        ]);

        $media = ProductMedia::create([
            'product_id' => $product->id,
            'url' => 'products/kompor.jpg',
            'type' => 'image',
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('product_media', ['id' => $media->id]);

        $product->forceDelete();

        $this->assertDatabaseMissing('product_media', ['id' => $media->id]);
    }
}
