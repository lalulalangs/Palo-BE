<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCategoryCollectionDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);
    }

    public function test_category_and_collection_fields_preload_options_without_requiring_search(): void
    {
        $schema = Schema::make(new CreateProduct);
        $configured = ProductForm::configure($schema);

        /** @var Section $basicSection */
        $basicSection = collect($configured->getComponents())
            ->first(fn ($component) => $component instanceof Section && $component->getHeading() === 'Informasi Dasar Produk');

        $this->assertNotNull($basicSection);

        $fields = collect($basicSection->getChildComponents())->keyBy(fn ($field) => $field->getName());

        /** @var Select $categoryField */
        $categoryField = $fields['category_id'];
        /** @var Select $collectionsField */
        $collectionsField = $fields['collections'];

        $this->assertInstanceOf(Select::class, $categoryField);
        $this->assertFalse($categoryField->isSearchable());

        $this->assertInstanceOf(Select::class, $collectionsField);
        $this->assertTrue($collectionsField->isMultiple());
        $this->assertTrue($collectionsField->isPreloaded());
    }

    public function test_create_product_page_displays_all_categories_and_collections_immediately(): void
    {
        $catA = Category::create(['name' => 'Tenda', 'slug' => 'tenda']);
        $catB = Category::create(['name' => 'Carrier', 'slug' => 'carrier']);

        $colA = Collection::create(['name' => 'Rinjani Series', 'slug' => 'rinjani-series', 'sort_order' => 1]);
        $colB = Collection::create(['name' => 'Semeru Series', 'slug' => 'semeru-series', 'sort_order' => 2]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateProduct::class)
            ->assertSuccessful()
            ->assertSee($catA->name)
            ->assertSee($catB->name)
            ->assertSee($colA->name)
            ->assertSee($colB->name);
    }

    public function test_edit_product_page_displays_all_categories_and_collections_immediately(): void
    {
        $catA = Category::create(['name' => 'Tenda', 'slug' => 'tenda']);
        $catB = Category::create(['name' => 'Sleeping Bag', 'slug' => 'sleeping-bag']);

        $colA = Collection::create(['name' => 'Rinjani Series', 'slug' => 'rinjani-series', 'sort_order' => 1]);
        $colB = Collection::create(['name' => 'Tambora Series', 'slug' => 'tambora-series', 'sort_order' => 2]);

        $product = Product::create([
            'category_id' => $catA->id,
            'name' => 'Tenda Dome 2P',
            'slug' => 'tenda-dome-2p',
            'base_price' => 850000,
            'is_active' => true,
        ]);
        $product->collections()->attach($colA->id);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($catA->name)
            ->assertSee($catB->name)
            ->assertSee($colA->name)
            ->assertSee($colB->name)
            ->assertSchemaStateSet([
                'category_id' => $catA->id,
                'collections' => [(string) $colA->id],
            ]);
    }

    public function test_can_create_and_edit_product_with_category_and_collections(): void
    {
        $catA = Category::create(['name' => 'Tenda', 'slug' => 'tenda']);
        $catB = Category::create(['name' => 'Jaket', 'slug' => 'jaket']);

        $colA = Collection::create(['name' => 'Rinjani Series', 'slug' => 'rinjani-series', 'sort_order' => 1]);
        $colB = Collection::create(['name' => 'Semeru Series', 'slug' => 'semeru-series', 'sort_order' => 2]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateProduct::class)
            ->fillForm([
                'category_id' => $catA->id,
                'collections' => [$colA->id, $colB->id],
                'name' => 'Jaket Gunung Windproof',
                'slug' => 'jaket-gunung-windproof',
                'base_price' => 450000,
                'is_active' => true,
                'media' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('slug', 'jaket-gunung-windproof')->firstOrFail();
        $this->assertSame($catA->id, $product->category_id);
        $this->assertEqualsCanonicalizing([$colA->id, $colB->id], $product->collections()->pluck('collections.id')->all());

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'category_id' => $catB->id,
                'collections' => [$colB->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame($catB->id, $product->category_id);
        $this->assertSame([$colB->id], $product->collections()->pluck('collections.id')->all());
    }

    public function test_category_and_collection_dropdown_queries_are_minimal_and_constant(): void
    {
        $cat = Category::create(['name' => 'Kategori 1', 'slug' => 'kategori-1']);
        $col = Collection::create(['name' => 'Koleksi 1', 'slug' => 'koleksi-1', 'sort_order' => 1]);

        $product = Product::create([
            'category_id' => $cat->id,
            'name' => 'Produk Uji Performa',
            'slug' => 'produk-uji-performa',
            'base_price' => 100000,
            'is_active' => true,
        ]);
        $product->collections()->attach($col->id);

        // Warm-up panel & role relation agar query boot tidak ikut terhitung.
        $this->admin->load('role');
        Livewire::actingAs($this->admin, 'admin')->test(CreateProduct::class);

        $smallCreate = $this->measureQueries(fn () => Livewire::actingAs($this->admin, 'admin')->test(CreateProduct::class));
        $smallEdit = $this->measureQueries(fn () => Livewire::actingAs($this->admin, 'admin')->test(EditProduct::class, ['record' => $product->getRouteKey()]));

        for ($i = 2; $i <= 15; $i++) {
            Category::create(['name' => "Kategori {$i}", 'slug' => "kategori-{$i}"]);
            Collection::create(['name' => "Koleksi {$i}", 'slug' => "koleksi-{$i}", 'sort_order' => $i]);
        }

        $largeCreate = $this->measureQueries(fn () => Livewire::actingAs($this->admin, 'admin')->test(CreateProduct::class));
        $largeEdit = $this->measureQueries(fn () => Livewire::actingAs($this->admin, 'admin')->test(EditProduct::class, ['record' => $product->getRouteKey()]));

        $this->assertSame($smallCreate['count'], $largeCreate['count']);
        $this->assertSame($smallEdit['count'], $largeEdit['count']);

        $categoryOptionQueries = collect($largeCreate['queries'])
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, '"categories"') || str_contains($sql, '`categories`'));

        $collectionOptionQueries = collect($largeCreate['queries'])
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, '"collections"') || str_contains($sql, '`collections`'));

        $this->assertNotEmpty($categoryOptionQueries);
        $this->assertNotEmpty($collectionOptionQueries);

        foreach ($categoryOptionQueries as $sql) {
            $this->assertStringNotContainsString('*', $sql);
        }

        foreach ($collectionOptionQueries as $sql) {
            $this->assertStringNotContainsString('*', $sql);
        }
    }

    /**
     * @return array{count: int, queries: array<int, array<string, mixed>>}
     */
    private function measureQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return [
            'count' => count($queries),
            'queries' => $queries,
        ];
    }
}
