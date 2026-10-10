<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SlugManagementTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);

        $this->category = Category::create([
            'name' => 'Outdoor Equipment',
            'slug' => 'outdoor-equipment',
        ]);
    }

    public function test_category_slug_is_auto_generated_on_create_page(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateCategory::class)
            ->set('data.name', 'Peralatan Tenda & Camping')
            ->assertSet('data.slug', 'peralatan-tenda-camping');
    }

    public function test_category_slug_remains_unchanged_when_name_is_updated_on_edit_page(): void
    {
        $category = Category::create([
            'name' => 'Carrier Bag',
            'slug' => 'carrier-bag',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditCategory::class, [
                'record' => $category->id,
            ])
            ->assertSet('data.slug', 'carrier-bag')
            ->set('data.name', 'Carrier Bag Edisi Baru')
            ->assertSet('data.slug', 'carrier-bag');
    }

    public function test_category_slug_can_be_manually_customized(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateCategory::class)
            ->set('data.name', 'Sepatu Gunung Anti Air')
            ->assertSet('data.slug', 'sepatu-gunung-anti-air')
            ->set('data.slug', 'sepatu-gunung-waterproof')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('categories', [
            'name' => 'Sepatu Gunung Anti Air',
            'slug' => 'sepatu-gunung-waterproof',
        ]);
    }

    public function test_product_slug_is_auto_generated_on_create_page(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateProduct::class)
            ->set('data.name', 'Jaket Gunung Rinjani Pro')
            ->assertSet('data.slug', 'jaket-gunung-rinjani-pro');
    }

    public function test_product_slug_remains_unchanged_when_name_is_updated_on_edit_page(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Sleeping Bag Polar',
            'slug' => 'sleeping-bag-polar',
            'base_price' => 200000.00,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditProduct::class, [
                'record' => $product->id,
            ])
            ->assertSet('data.slug', 'sleeping-bag-polar')
            ->set('data.name', 'Sleeping Bag Polar Ultra Warm')
            ->assertSet('data.slug', 'sleeping-bag-polar');
    }

    public function test_model_creating_hook_auto_generates_slug_and_handles_collision(): void
    {
        $cat1 = Category::create(['name' => 'Tenda Dome']);
        $this->assertEquals('tenda-dome', $cat1->slug);

        $cat2 = Category::create(['name' => 'Tenda Dome']);
        $this->assertEquals('tenda-dome-1', $cat2->slug);

        $prod1 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Matras Alumunium Foil',
            'base_price' => 50000.00,
        ]);
        $this->assertEquals('matras-alumunium-foil', $prod1->slug);

        $prod2 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Matras Alumunium Foil',
            'base_price' => 50000.00,
        ]);
        $this->assertEquals('matras-alumunium-foil-1', $prod2->slug);
    }

    public function test_can_bulk_delete_products(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Produk Akan Dihapus',
            'slug' => 'produk-akan-dihapus',
            'base_price' => 100000.00,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListProducts::class)
            ->callTableBulkAction('delete', [$product]);

        $this->assertSoftDeleted('products', [
            'id' => $product->id,
        ]);
    }

    public function test_can_bulk_delete_categories(): void
    {
        $category = Category::create([
            'name' => 'Kategori Dihapus',
            'slug' => 'kategori-dihapus',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListCategories::class)
            ->callTableBulkAction('delete', [$category]);

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_can_select_category_in_product_form(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateProduct::class)
            ->set('data.category_id', $this->category->id)
            ->assertSet('data.category_id', $this->category->id);
    }
}
