<?php

namespace Tests\Feature\Inventory;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected Sku $sku;

    protected StockMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create(['email' => 'admin@palorinjani.com']);
        $this->service = app(StockMovementService::class);

        $category = Category::create(['name' => 'T-Shirt', 'slug' => 't-shirt']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kaos Rinjani 3726',
            'slug' => 'kaos-rinjani-3726',
            'base_price' => 150000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Hitam - L',
            'attributes' => ['color' => 'Hitam', 'size' => 'L'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PALO-RINJANI-BLK-L',
            'stock' => 10,
        ]);
    }

    public function test_record_movement_increments_stock_on_restock(): void
    {
        $movement = $this->service->recordMovement(
            sku: $this->sku,
            quantityChange: 15,
            type: StockMovement::TYPE_RESTOCK,
            userId: $this->admin->id,
            notes: 'Restock supplier gelombang 1'
        );

        $this->assertEquals(10, $movement->stock_before);
        $this->assertEquals(25, $movement->stock_after);
        $this->assertEquals(15, $movement->quantity_change);
        $this->assertEquals($this->admin->id, $movement->user_id);
        $this->assertEquals(StockMovement::TYPE_RESTOCK, $movement->type);

        $this->sku->refresh();
        $this->assertEquals(25, $this->sku->stock);
    }

    public function test_record_movement_decrements_stock_on_sale(): void
    {
        $movement = $this->service->recordMovement(
            sku: $this->sku,
            quantityChange: -3,
            type: StockMovement::TYPE_SALE,
            userId: null,
            notes: 'Checkout pelanggan web'
        );

        $this->assertEquals(10, $movement->stock_before);
        $this->assertEquals(7, $movement->stock_after);
        $this->assertEquals(-3, $movement->quantity_change);
        $this->assertNull($movement->user_id);

        $this->sku->refresh();
        $this->assertEquals(7, $this->sku->stock);
    }

    public function test_record_movement_prevents_negative_stock_and_rolls_back(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stok SKU PALO-RINJANI-BLK-L tidak mencukupi');

        try {
            $this->service->recordMovement(
                sku: $this->sku,
                quantityChange: -15, // stok hanya 10
                type: StockMovement::TYPE_SALE
            );
        } finally {
            $this->sku->refresh();
            $this->assertEquals(10, $this->sku->stock);
            $this->assertEquals(0, StockMovement::count());
        }
    }

    public function test_apply_opname_updates_stock_and_creates_adjustment_movements(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-TEST-001',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
            'notes' => 'Audit fisik bulanan',
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->sku->id,
            'system_stock' => 10,
            'physical_stock' => 8, // Ada selisih -2
            'difference' => -2,
            'notes' => 'Cacat benang 2 pcs',
        ]);

        $this->service->applyOpname($opname, $this->admin->id);

        $this->sku->refresh();
        $this->assertEquals(8, $this->sku->stock);

        $opname->refresh();
        $this->assertEquals(StockOpname::STATUS_COMPLETED, $opname->status);
        $this->assertNotNull($opname->completed_at);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'user_id' => $this->admin->id,
            'type' => StockMovement::TYPE_OPNAME_ADJUSTMENT,
            'quantity_change' => -2,
            'stock_before' => 10,
            'stock_after' => 8,
            'reference_type' => StockOpname::class,
            'reference_id' => $opname->id,
        ]);
    }

    public function test_cannot_apply_completed_opname(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-TEST-002',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak dalam status draft dan tidak dapat diterapkan');

        $this->service->applyOpname($opname);
    }
}
