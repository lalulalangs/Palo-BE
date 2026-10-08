<?php

namespace Tests\Feature\Inventory;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockOpnames\Pages\CreateStockOpname;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class StockEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Sku $skuA;

    protected Sku $skuB;

    protected Sku $skuC;

    protected StockMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin.edgecase@palorinjani.com',
            'name' => 'Admin QA',
        ]);

        $this->service = app(StockMovementService::class);

        $category = Category::create(['name' => 'Perlengkapan', 'slug' => 'perlengkapan']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Tenda Dome Rinjani',
            'slug' => 'tenda-dome-rinjani',
            'base_price' => 850000,
        ]);

        $varA = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Kapasitas 2P',
            'attributes' => ['capacity' => '2P'],
        ]);
        $varB = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Kapasitas 4P',
            'attributes' => ['capacity' => '4P'],
        ]);
        $varC = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Kapasitas 6P',
            'attributes' => ['capacity' => '6P'],
        ]);

        $this->skuA = Sku::create(['variant_id' => $varA->id, 'sku_code' => 'TND-2P', 'stock' => 10]);
        $this->skuB = Sku::create(['variant_id' => $varB->id, 'sku_code' => 'TND-4P', 'stock' => 10]);
        $this->skuC = Sku::create(['variant_id' => $varC->id, 'sku_code' => 'TND-6P', 'stock' => 10]);
    }

    public function test_zero_quantity_change_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Perubahan kuantitas stok tidak boleh bernilai nol (0)');

        $this->service->recordMovement($this->skuA, 0, StockMovement::TYPE_MANUAL_ADJUSTMENT);
    }

    public function test_exact_drain_boundary_to_zero_stock(): void
    {
        $movement = $this->service->recordMovement(
            sku: $this->skuA,
            quantityChange: -10,
            type: StockMovement::TYPE_SALE
        );

        $this->skuA->refresh();
        $this->assertEquals(0, $this->skuA->stock);
        $this->assertEquals(10, $movement->stock_before);
        $this->assertEquals(0, $movement->stock_after);
        $this->assertEquals(-10, $movement->quantity_change);
    }

    public function test_decrement_on_already_zero_stock_is_rejected(): void
    {
        // Habiskan stok terlebih dahulu
        $this->service->recordMovement($this->skuA, -10, StockMovement::TYPE_SALE);
        $this->skuA->refresh();
        $this->assertEquals(0, $this->skuA->stock);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stok SKU TND-2P tidak mencukupi');

        $this->service->recordMovement($this->skuA, -1, StockMovement::TYPE_SALE);
    }

    public function test_large_positive_restock_boundary(): void
    {
        $movement = $this->service->recordMovement(
            sku: $this->skuA,
            quantityChange: 100000,
            type: StockMovement::TYPE_RESTOCK
        );

        $this->skuA->refresh();
        $this->assertEquals(100010, $this->skuA->stock);
        $this->assertEquals(100010, $movement->stock_after);
    }

    public function test_opname_with_zero_difference_does_not_create_redundant_movement(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-ZERO-DIFF',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->skuA->id,
            'system_stock' => 10,
            'physical_stock' => 10, // Sama persis
            'difference' => 0,
        ]);

        $this->service->applyOpname($opname, $this->admin->id);

        $this->skuA->refresh();
        $this->assertEquals(10, $this->skuA->stock);

        $opname->refresh();
        $this->assertEquals(StockOpname::STATUS_COMPLETED, $opname->status);

        // Tidak ada baris mutasi mubazir yang terbuat
        $this->assertEquals(0, StockMovement::where('sku_id', $this->skuA->id)->count());
    }

    public function test_opname_rejects_negative_physical_stock(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-NEG-PHYSICAL',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->skuA->id,
            'system_stock' => 10,
            'physical_stock' => -5,
            'difference' => -15,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak boleh bernilai negatif');

        $this->service->applyOpname($opname, $this->admin->id);
    }

    public function test_opname_reflects_concurrent_stock_changes_since_draft_creation(): void
    {
        // 1. Draft dibuat saat stok sistem masih 10, fisik terhitung 8
        $opname = StockOpname::create([
            'opname_number' => 'OPN-CONCURRENT',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        $item = StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->skuA->id,
            'system_stock' => 10,
            'physical_stock' => 8,
            'difference' => -2,
        ]);

        // 2. Di tengah jalan ada penjualan online (stok berkurang 4 pcs menjadi 6)
        $this->service->recordMovement($this->skuA, -4, StockMovement::TYPE_SALE);
        $this->skuA->refresh();
        $this->assertEquals(6, $this->skuA->stock);

        // 3. Admin menerapkan opname. Target fisik di gudang adalah 8!
        // Sistem harus menyesuaikan dari 6 ke 8 (+2)
        $this->service->applyOpname($opname, $this->admin->id);

        $this->skuA->refresh();
        $this->assertEquals(8, $this->skuA->stock);

        $item->refresh();
        $this->assertEquals(6, $item->system_stock);
        $this->assertEquals(2, $item->difference);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->skuA->id,
            'type' => StockMovement::TYPE_OPNAME_ADJUSTMENT,
            'quantity_change' => 2,
            'stock_before' => 6,
            'stock_after' => 8,
        ]);
    }

    public function test_opname_rejects_empty_items(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-EMPTY',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak memiliki item untuk disesuaikan');

        $this->service->applyOpname($opname);
    }

    public function test_opname_cannot_be_applied_when_cancelled(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-CANCELLED',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_CANCELLED,
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->skuA->id,
            'system_stock' => 10,
            'physical_stock' => 12,
            'difference' => 2,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak dalam status draft dan tidak dapat diterapkan');

        $this->service->applyOpname($opname);
    }

    public function test_multiple_skus_in_single_opname_with_mixed_outcomes(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-MIXED',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        // SKU A: Surplus (+2)
        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->skuA->id,
            'system_stock' => 10,
            'physical_stock' => 12,
            'difference' => 2,
        ]);

        // SKU B: Minus (-3)
        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->skuB->id,
            'system_stock' => 10,
            'physical_stock' => 7,
            'difference' => -3,
        ]);

        // SKU C: Imbang (0)
        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->skuC->id,
            'system_stock' => 10,
            'physical_stock' => 10,
            'difference' => 0,
        ]);

        $this->service->applyOpname($opname, $this->admin->id);

        $this->skuA->refresh();
        $this->skuB->refresh();
        $this->skuC->refresh();

        $this->assertEquals(12, $this->skuA->stock);
        $this->assertEquals(7, $this->skuB->stock);
        $this->assertEquals(10, $this->skuC->stock);

        // Hanya 2 mutasi yang dibuat (SKU A dan B)
        $this->assertEquals(2, StockMovement::where('reference_type', StockOpname::class)->count());
    }

    public function test_filament_opname_rejects_negative_physical_stock_in_form(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateStockOpname::class)
            ->fillForm([
                'user_id' => $this->admin->id,
                'items' => [
                    [
                        'sku_id' => $this->skuA->id,
                        'system_stock' => 10,
                        'physical_stock' => -2, // Negatif tidak diizinkan
                        'difference' => -12,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['items.0.physical_stock']);
    }

    public function test_filament_quick_adjustment_rejects_zero_quantity_change(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListStockMovements::class)
            ->callAction('quick_adjustment', [
                'sku_id' => $this->skuA->id,
                'type' => StockMovement::TYPE_RESTOCK,
                'quantity_change' => 0, // Tidak boleh nol
                'notes' => 'Coba mutasi nol',
            ])
            ->assertHasActionErrors(['quantity_change']);
    }
}
