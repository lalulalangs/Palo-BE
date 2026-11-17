<?php

namespace Tests\Feature\Filament;

use App\Filament\Components\SkuSelect;
use App\Filament\Resources\StockOpnames\Pages\CreateStockOpname;
use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class StockOpnameResourceTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
            'name' => 'Admin Opname',
        ]);

        $category = Category::create(['name' => 'Aksesoris', 'slug' => 'aksesoris']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Topi Rimba',
            'slug' => 'topi-rimba',
            'base_price' => 85000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Olive - All Size',
            'attributes' => ['color' => 'Olive'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'ACC-TPI-OLV-ALL',
            'stock' => 20,
        ]);
    }

    public function test_can_render_stock_opnames_list_page(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-TEST-101',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
            'notes' => 'Opname sesi 1',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockOpnames::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$opname]);
    }

    public function test_can_create_draft_stock_opname_with_items(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateStockOpname::class)
            ->assertSee('Status Sesi')
            ->assertSee('Draft (Baru)')
            ->fillForm([
                'user_id' => $this->admin->id,
                'notes' => 'Audit rak topi Senaru',
                'items' => [
                    [
                        'sku_id' => $this->sku->id,
                        'system_stock' => 20,
                        'physical_stock' => 18,
                        'difference' => -2,
                        'notes' => '2 pcs rusak kena air',
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(StockOpnameResource::getUrl('index'));

        $this->assertDatabaseHas('stock_opnames', [
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
            'notes' => 'Audit rak topi Senaru',
        ]);

        $this->assertDatabaseHas('stock_opname_items', [
            'sku_id' => $this->sku->id,
            'system_stock' => 20,
            'physical_stock' => 18,
            'difference' => -2,
        ]);

        // Stok SKU belum berubah karena masih draft
        $this->sku->refresh();
        $this->assertEquals(20, $this->sku->stock);
    }

    public function test_can_apply_opname_from_edit_page(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-APPLY-001',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
            'notes' => 'Audit siap terapkan',
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->sku->id,
            'system_stock' => 20,
            'physical_stock' => 15,
            'difference' => -5,
            'notes' => 'Hilang saat display gerai',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditStockOpname::class, ['record' => $opname->getKey()])
            ->assertSee('Status Sesi')
            ->assertSee('Draft Aktif')
            ->callAction('apply_opname')
            ->assertHasNoActionErrors()
            ->assertNotified('Penyesuaian stok opname berhasil diterapkan');

        // SKU stock harus terupdate menjadi 15
        $this->sku->refresh();
        $this->assertEquals(15, $this->sku->stock);

        // Status opname harus completed
        $opname->refresh();
        $this->assertEquals(StockOpname::STATUS_COMPLETED, $opname->status);
        $this->assertNotNull($opname->completed_at);

        // Mutasi stok harus tercatat
        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'user_id' => $this->admin->id,
            'type' => StockMovement::TYPE_OPNAME_ADJUSTMENT,
            'quantity_change' => -5,
            'stock_before' => 20,
            'stock_after' => 15,
            'reference_type' => StockOpname::class,
            'reference_id' => $opname->id,
        ]);
    }

    public function test_can_cancel_draft_stock_opname_from_edit_page(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-CANCEL-001',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
            'notes' => 'Sesi yang akan dibatalkan',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditStockOpname::class, ['record' => $opname->getKey()])
            ->callAction('cancel_opname')
            ->assertHasNoActionErrors()
            ->assertNotified('Sesi opname berhasil dibatalkan')
            ->assertRedirect(StockOpnameResource::getUrl('index'));

        $opname->refresh();
        $this->assertTrue($opname->isCancelled());
        $this->assertEquals(StockOpname::STATUS_CANCELLED, $opname->status);
    }

    public function test_can_cancel_draft_stock_opname_from_table_action(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-CANCEL-TBL',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockOpnames::class)
            ->callTableAction('cancel_opname', $opname)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Sesi opname berhasil dibatalkan');

        $opname->refresh();
        $this->assertTrue($opname->isCancelled());
    }

    public function test_can_delete_draft_stock_opname_from_table(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-DELETE-001',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->sku->id,
            'system_stock' => 20,
            'physical_stock' => 20,
            'difference' => 0,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockOpnames::class)
            ->callTableAction('delete', $opname)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('stock_opnames', ['id' => $opname->id]);
        $this->assertDatabaseMissing('stock_opname_items', ['stock_opname_id' => $opname->id]);
    }

    public function test_completed_opname_hides_cancel_and_delete_actions(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-COMPLETED-SECURE',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockOpnames::class)
            ->assertTableActionHidden('cancel_opname', $opname)
            ->assertTableActionHidden('delete', $opname)
            ->assertTableActionHidden('apply_opname', $opname);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditStockOpname::class, ['record' => $opname->getKey()])
            ->assertActionHidden('cancel_opname')
            ->assertActionHidden('delete')
            ->assertActionHidden('apply_opname');
    }

    public function test_editing_draft_opname_redirects_to_index_after_save(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-EDIT-SAVE',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
            'notes' => 'Catatan sebelum diedit',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditStockOpname::class, ['record' => $opname->getKey()])
            ->fillForm([
                'notes' => 'Catatan setelah diperbarui',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Perubahan draft opname berhasil disimpan')
            ->assertRedirect(StockOpnameResource::getUrl('index'));

        $opname->refresh();
        $this->assertEquals('Catatan setelah diperbarui', $opname->notes);
    }

    public function test_sku_select_search_on_create_stock_opname_qualifies_products_name_and_matches_sku_or_product(): void
    {
        $category = Category::firstOrFail();
        $otherProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Gunung',
            'slug' => 'jaket-gunung',
            'base_price' => 350000,
        ]);
        $otherVariant = ProductVariant::create([
            'product_id' => $otherProduct->id,
            'name' => 'Hitam - L',
            'attributes' => ['color' => 'Hitam', 'size' => 'L'],
        ]);
        $otherSku = Sku::create([
            'variant_id' => $otherVariant->id,
            'sku_code' => 'JKT-GNG-BLK-L',
            'stock' => 5,
        ]);

        $livewire = Livewire::actingAs($this->admin, 'admin')
            ->test(CreateStockOpname::class);

        $skuSelect = collect($livewire->instance()->getSchema('form')->getFlatFields(withHidden: true))
            ->first(fn ($component) => $component instanceof SkuSelect);

        if (! $skuSelect) {
            $repeater = $livewire->instance()->getSchema('form')->getFlatFields(withHidden: true)['items'] ?? null;
            $this->assertNotNull($repeater, 'Repeater items harus tersedia di form CreateStockOpname.');
            $skuSelect = collect($repeater->getChildSchemas())
                ->flatMap(fn ($schema) => $schema->getFlatFields(withHidden: true))
                ->first(fn ($component) => $component instanceof SkuSelect);
        }

        $this->assertInstanceOf(SkuSelect::class, $skuSelect);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $byCode = $skuSelect->getSearchResults('ACC-TPI');
        $byProductName = $skuSelect->getSearchResults('Topi Rimba');

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertArrayHasKey($this->sku->id, $byCode);
        $this->assertArrayNotHasKey($otherSku->id, $byCode);

        $this->assertArrayHasKey($this->sku->id, $byProductName);
        $this->assertArrayNotHasKey($otherSku->id, $byProductName);

        $skuSearchQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, '"skus"') && str_contains($sql, '"products"'));
        $this->assertNotEmpty($skuSearchQueries);

        foreach ($skuSearchQueries as $sql) {
            $this->assertStringContainsString('"products"."name"', $sql);
            $this->assertStringContainsString('"skus"."sku_code"', $sql);
            $this->assertDoesNotMatchRegularExpression('/(?<!\.)"name"/', $sql);
        }
    }

    public function test_cancelled_opname_locks_items_repeater_and_blocks_saving(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-CANCELLED-LOCK',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_CANCELLED,
            'notes' => 'Catatan awal batal',
        ]);

        $item = StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->sku->id,
            'system_stock' => 20,
            'physical_stock' => 18,
            'difference' => -2,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditStockOpname::class, ['record' => $opname->getKey()])
            ->assertDontSee('Simpan Perubahan Draft')
            ->assertActionHidden('apply_opname')
            ->assertActionHidden('cancel_opname')
            ->assertFormComponentExists('items', fn (Repeater $component): bool => $component->isDisabled())
            ->fillForm([
                'notes' => 'Percobaan ubah catatan batal',
            ])
            ->call('save')
            ->assertNotified('Sesi opname sudah terkunci dan tidak dapat diubah');

        $opname->refresh();
        $item->refresh();
        $this->assertSame('Catatan awal batal', $opname->notes);
        $this->assertSame(18, $item->physical_stock);
    }
}
