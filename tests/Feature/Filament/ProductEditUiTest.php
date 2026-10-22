<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductEditUiTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected Category $category;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);

        $this->category = Category::create([
            'name' => 'Tenda & Shelter',
            'slug' => 'tenda-dan-shelter',
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Tenda Rinjani Summit 4P',
            'slug' => 'tenda-rinjani-summit-4p',
            'base_price' => 1500000.00,
            'description' => 'Tenda ekspedisi 4 musim dengan ketahanan angin ekstrem.',
            'is_active' => true,
            'is_featured' => false,
        ]);
    }

    public function test_can_render_product_edit_page_with_status_and_visibility_fields(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->assertSuccessful()
            ->assertFormFieldExists('is_active')
            ->assertFormFieldExists('is_featured')
            ->assertSchemaStateSet([
                'is_active' => true,
                'is_featured' => false,
            ]);
    }

    public function test_status_and_visibility_section_has_full_width_and_configured_toggles(): void
    {
        $livewire = new EditProduct;
        $livewire->record = $this->product;

        $schema = Schema::make($livewire);
        $configured = ProductForm::configure($schema);

        /** @var Section $statusSection */
        $statusSection = collect($configured->getComponents())
            ->first(fn ($component) => $component instanceof Section && $component->getHeading() === 'Status & Visibilitas Produk');

        $this->assertNotNull($statusSection);
        $this->assertEquals(['default' => 'full'], $statusSection->getColumnSpan());
        $this->assertEquals(['default' => 1, 'md' => 2], $statusSection->getColumns());

        $fields = collect($statusSection->getChildComponents())->keyBy(fn ($f) => $f->getName());

        $this->assertTrue($fields->has('is_active'));
        $this->assertTrue($fields->has('is_featured'));

        $this->assertFalse($fields['is_active']->isInline());
        $this->assertFalse($fields['is_featured']->isInline());
        $this->assertNull($fields['is_featured']->getOffIcon());
    }

    public function test_can_update_product_status_and_featured_toggle(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->fillForm([
                'is_active' => false,
                'is_featured' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->product->refresh();
        $this->assertFalse((bool) $this->product->is_active);
        $this->assertTrue((bool) $this->product->is_featured);
    }

    public function test_admin_texts_and_buttons_are_localized_to_indonesian(): void
    {
        $this->assertEquals('id', config('app.locale'));
        $this->assertEquals('Simpan', __('Save'));
        $this->assertEquals('Tambah', __('Add'));

        $page = new EditProduct;
        $page->record = $this->product;

        $refSave = new \ReflectionMethod($page, 'getSaveFormAction');
        $this->assertEquals('Simpan', $refSave->invoke($page)->getLabel());

        $refCancel = new \ReflectionMethod($page, 'getCancelFormAction');
        $this->assertEquals('Batal', $refCancel->invoke($page)->getLabel());

        $variantsRel = new VariantsRelationManager;
        $variantsRel->ownerRecord = $this->product;
        $this->assertEquals('Varian & Stok Produk', $variantsRel->getTitle($this->product, 'variants'));
    }
}
