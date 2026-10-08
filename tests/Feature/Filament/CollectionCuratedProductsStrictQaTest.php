<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Collections\Pages\EditCollection;
use App\Filament\Resources\Collections\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CollectionCuratedProductsStrictQaTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin.qa@palorinjani.com',
        ]);

        $this->category = Category::create([
            'name' => 'Trekking & Mountaineering',
            'slug' => 'trekking-mountaineering',
        ]);
    }

    /**
     * QA Test 1: Validasi form modal AttachAction - recordId wajib diisi.
     */
    public function test_qa_attach_action_rejects_empty_record_id(): void
    {
        $collection = Collection::create([
            'name' => 'Rinjani Volcano Series',
            'slug' => 'rinjani-volcano-series',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->mountTableAction('attach')
            ->set('mountedActions.0.data.recordId', null)
            ->set('mountedActions.0.data.sort_order', 1)
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['recordId']);

        $this->assertCount(0, $collection->fresh()->products);
    }

    /**
     * QA Test 2: Validasi form modal AttachAction - sort_order wajib berupa angka.
     */
    public function test_qa_attach_action_rejects_non_numeric_sort_order(): void
    {
        $collection = Collection::create([
            'name' => 'Alpine Technical Gear',
            'slug' => 'alpine-technical-gear',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Crampon 12-Points',
            'slug' => 'crampon-12-points',
            'base_price' => 850000,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->mountTableAction('attach')
            ->set('mountedActions.0.data.recordId', $product->id)
            ->set('mountedActions.0.data.sort_order', 'not-a-number')
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['sort_order']);

        $this->assertCount(0, $collection->fresh()->products);
    }

    /**
     * QA Test 3: EditAction pada tabel relasi mampu mengubah nilai pivot sort_order tanpa merusak entitas produk.
     */
    public function test_qa_can_edit_pivot_sort_order_via_edit_action(): void
    {
        $collection = Collection::create([
            'name' => 'Ultralight Series',
            'slug' => 'ultralight-series',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Tenda Tarp Ultralight 1P',
            'slug' => 'tenda-tarp-ultralight-1p',
            'base_price' => 450000,
            'is_active' => true,
        ]);

        $collection->products()->attach($product->id, ['sort_order' => 10]);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->callTableAction('edit', $product, [
                'sort_order' => 1,
            ])
            ->assertHasNoTableActionErrors();

        $attached = $collection->fresh()->products()->where('product_id', $product->id)->first();
        $this->assertNotNull($attached);
        $this->assertEquals(1, $attached->pivot->sort_order);

        // Pastikan atribut asli produk tidak berubah sama sekali
        $this->assertEquals('Tenda Tarp Ultralight 1P', $product->fresh()->name);
        $this->assertEquals(450000, $product->fresh()->base_price);
    }

    /**
     * QA Test 4: DetachAction menghapus relasi pivot tanpa menghapus produk dari database katalog.
     */
    public function test_qa_detach_removes_pivot_while_preserving_catalog_product(): void
    {
        $collection = Collection::create([
            'name' => 'Footwear Series',
            'slug' => 'footwear-series',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Sepatu Gunung Rinjani Pro',
            'slug' => 'sepatu-gunung-rinjani-pro',
            'base_price' => 1100000,
            'is_active' => true,
        ]);

        $collection->products()->attach($product->id, ['sort_order' => 5]);
        $this->assertCount(1, $collection->fresh()->products);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->callTableAction('detach', $product)
            ->assertHasNoTableActionErrors();

        // Relasi pivot harus hilang
        $this->assertCount(0, $collection->fresh()->products);
        $this->assertDatabaseMissing('collection_product', [
            'collection_id' => $collection->id,
            'product_id' => $product->id,
        ]);

        // Produk di tabel products harus tetap eksis
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'slug' => 'sepatu-gunung-rinjani-pro',
        ]);
    }

    /**
     * QA Test 5: DetachBulkAction mampu mengeluarkan beberapa produk terpilih sekaligus.
     */
    public function test_qa_detach_bulk_action_removes_selected_products(): void
    {
        $collection = Collection::create([
            'name' => 'Apparel Drop',
            'slug' => 'apparel-drop',
            'is_active' => true,
        ]);

        $p1 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Kaos Quickdry 01',
            'slug' => 'kaos-quickdry-01',
            'base_price' => 125000,
            'is_active' => true,
        ]);

        $p2 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Celana Trekking 02',
            'slug' => 'celana-trekking-02',
            'base_price' => 250000,
            'is_active' => true,
        ]);

        $p3 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Topi Rimba 03',
            'slug' => 'topi-rimba-03',
            'base_price' => 75000,
            'is_active' => true,
        ]);

        $collection->products()->attach([
            $p1->id => ['sort_order' => 1],
            $p2->id => ['sort_order' => 2],
            $p3->id => ['sort_order' => 3],
        ]);

        $this->assertCount(3, $collection->fresh()->products);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->callTableBulkAction('detach', [$p1, $p2])
            ->assertHasNoTableActionErrors();

        $fresh = $collection->fresh()->products;
        $this->assertCount(1, $fresh);
        $this->assertEquals($p3->id, $fresh->first()->id);
    }

    /**
     * QA Test 6: ImageColumn menangani produk dengan foto thumbnail maupun tanpa foto secara aman (graceful placeholder).
     */
    public function test_qa_table_renders_products_with_and_without_thumbnails(): void
    {
        $collection = Collection::create([
            'name' => 'Hydration Gear',
            'slug' => 'hydration-gear',
            'is_active' => true,
        ]);

        // Produk dengan thumbnail
        $pWithImage = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Water Bladder 2L',
            'slug' => 'water-bladder-2l',
            'base_price' => 150000,
            'is_active' => true,
        ]);
        ProductMedia::create([
            'product_id' => $pWithImage->id,
            'url' => 'products/water-bladder.webp',
            'type' => 'image',
            'sort_order' => 0,
        ]);

        // Produk tanpa thumbnail
        $pWithoutImage = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Botol Lipat 500ml',
            'slug' => 'botol-lipat-500ml',
            'base_price' => 60000,
            'is_active' => true,
        ]);

        $collection->products()->attach([
            $pWithImage->id => ['sort_order' => 1],
            $pWithoutImage->id => ['sort_order' => 2],
        ]);

        Livewire::actingAs($this->admin)
            ->test(ProductsRelationManager::class, [
                'ownerRecord' => $collection,
                'pageClass' => EditCollection::class,
            ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$pWithImage, $pWithoutImage]);
    }

    /**
     * QA Test 7: Integritas API Publik - Urutan produk di /api/v1/collections/{slug} mematuhi pivot sort_order
     * dan menyaring produk yang berstatus tidak aktif.
     */
    public function test_qa_public_api_reflects_pivot_sort_order_and_filters_inactive(): void
    {
        $collection = Collection::create([
            'name' => 'Peak Performance Collection',
            'slug' => 'peak-performance',
            'is_active' => true,
        ]);

        $pRank3 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Produk Urutan 3',
            'slug' => 'produk-urutan-3',
            'base_price' => 300000,
            'is_active' => true,
        ]);

        $pRank1 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Produk Urutan 1',
            'slug' => 'produk-urutan-1',
            'base_price' => 100000,
            'is_active' => true,
        ]);

        $pInactive = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Produk Nonaktif',
            'slug' => 'produk-nonaktif',
            'base_price' => 200000,
            'is_active' => false, // Nonaktif!
        ]);

        $collection->products()->attach([
            $pRank3->id => ['sort_order' => 3],
            $pRank1->id => ['sort_order' => 1],
            $pInactive->id => ['sort_order' => 2],
        ]);

        $response = $this->getJson('/api/v1/collections/peak-performance');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $items = $response->json('data.products');

        // Harus berisi 2 produk (produk nonaktif disaring keluar)
        $this->assertCount(2, $items);

        // Produk urutan 1 harus berada di posisi indeks ke-0
        $this->assertEquals('Produk Urutan 1', $items[0]['name']);
        $this->assertEquals('produk-urutan-1', $items[0]['slug']);

        // Produk urutan 3 harus berada di posisi indeks ke-1
        $this->assertEquals('Produk Urutan 3', $items[1]['name']);
        $this->assertEquals('produk-urutan-3', $items[1]['slug']);
    }

    /**
     * QA Test 8: Kurasi dua arah - ProductForm dapat menugaskan produk ke dalam koleksi saat membuat produk baru.
     */
    public function test_qa_product_can_be_assigned_to_collections_from_product_form(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $photo = \Illuminate\Http\UploadedFile::fake()->image('jacket.jpg', 600, 600);

        $c1 = Collection::create(['name' => 'Koleksi 1', 'slug' => 'koleksi-1', 'is_active' => true]);
        $c2 = Collection::create(['name' => 'Koleksi 2', 'slug' => 'koleksi-2', 'is_active' => true]);

        Livewire::actingAs($this->admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->category->id,
                'name' => 'Jaket Windproof Rinjani',
                'slug' => 'jaket-windproof-rinjani',
                'base_price' => 320000,
                'is_active' => true,
                'collections' => [$c1->id, $c2->id],
                'media' => [
                    [
                        'url' => [$photo],
                        'type' => 'image',
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('slug', 'jaket-windproof-rinjani')->first();
        $this->assertNotNull($product);
        $this->assertCount(2, $product->collections);
        $this->assertTrue($product->collections->contains($c1));
        $this->assertTrue($product->collections->contains($c2));
    }
}
