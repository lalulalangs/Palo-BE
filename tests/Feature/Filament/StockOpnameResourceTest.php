<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StockOpnames\Pages\CreateStockOpname;
use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockOpnameResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
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

        Livewire::actingAs($this->admin)
            ->test(ListStockOpnames::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$opname]);
    }

    public function test_can_create_draft_stock_opname_with_items(): void
    {
        Livewire::actingAs($this->admin)
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
            ->assertHasNoFormErrors();

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

        Livewire::actingAs($this->admin)
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
}
