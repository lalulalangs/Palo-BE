<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductVariantSkuManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);

        $category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Gunung Rinjani',
            'slug' => 'jaket-gunung-rinjani',
            'description' => 'Jaket tahan angin dan air',
            'base_price' => 450000.00,
            'is_featured' => true,
            'is_active' => true,
        ]);
    }

    public function test_can_render_variants_relation_manager_on_product_edit_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(VariantsRelationManager::class, [
                'ownerRecord' => $this->product,
                'pageClass' => EditProduct::class,
            ])
            ->assertSuccessful();
    }

    public function test_can_create_variant_with_sku_stock_and_price_override(): void
    {
        Livewire::actingAs($this->admin)
            ->test(VariantsRelationManager::class, [
                'ownerRecord' => $this->product,
                'pageClass' => EditProduct::class,
            ])
            ->callTableAction('create', data: [
                'name' => 'Navy Blue / Size XL',
                'attributes' => [
                    'color' => 'Navy Blue',
                    'size' => 'XL',
                ],
                'skus' => [
                    [
                        'sku_code' => 'PR-JKT-NVY-XL',
                        'stock' => 15,
                        'price_override' => 475000.00,
                        'is_active' => true,
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $this->product->id,
            'name' => 'Navy Blue / Size XL',
        ]);

        $variant = ProductVariant::where('name', 'Navy Blue / Size XL')->first();
        $this->assertNotNull($variant);
        $this->assertEquals(['color' => 'Navy Blue', 'size' => 'XL'], $variant->attributes);

        $this->assertDatabaseHas('skus', [
            'variant_id' => $variant->id,
            'sku_code' => 'PR-JKT-NVY-XL',
            'stock' => 15,
            'price_override' => 475000.00,
            'is_active' => true,
        ]);

        // Test relationships on model
        $this->assertCount(1, $variant->skus);
        $this->assertEquals('PR-JKT-NVY-XL', $variant->sku->sku_code);
    }

    public function test_can_update_variant_and_sku_data(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Forest Green / Size M',
            'attributes' => ['color' => 'Forest Green', 'size' => 'M'],
        ]);

        $sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PR-JKT-GRN-M',
            'stock' => 20,
            'price_override' => 450000.00,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(VariantsRelationManager::class, [
                'ownerRecord' => $this->product,
                'pageClass' => EditProduct::class,
            ])
            ->callTableAction('edit', $variant, data: [
                'name' => 'Forest Green / Size M (Updated)',
                'attributes' => ['color' => 'Forest Green', 'size' => 'M', 'material' => 'GoreTex'],
                'skus' => [
                    [
                        'id' => $sku->id,
                        'sku_code' => 'PR-JKT-GRN-M-V2',
                        'stock' => 50,
                        'price_override' => 460000.00,
                        'is_active' => true,
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'name' => 'Forest Green / Size M (Updated)',
        ]);

        $this->assertDatabaseHas('skus', [
            'variant_id' => $variant->id,
            'sku_code' => 'PR-JKT-GRN-M-V2',
            'stock' => 50,
            'price_override' => 460000.00,
        ]);
    }

    public function test_deleting_variant_cascades_to_sku(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Army / Size S',
        ]);

        $sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PR-JKT-ARM-S',
            'stock' => 10,
            'price_override' => 450000.00,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(VariantsRelationManager::class, [
                'ownerRecord' => $this->product,
                'pageClass' => EditProduct::class,
            ])
            ->callTableAction('delete', $variant)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseMissing('skus', ['id' => $sku->id]);
    }
}
