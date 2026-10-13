<?php

namespace Tests\Feature\Inventory;

use App\Filament\Actions\CreateVariantMatrix;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuStockObserverTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected Product $product;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin-observer@palorinjani.com',
            'name' => 'Admin Inventory',
        ]);

        $category = Category::create(['name' => 'T-Shirt', 'slug' => 't-shirt']);
        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kaos Rinjani 3726',
            'slug' => 'kaos-rinjani-3726',
            'base_price' => 150000,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Hitam / Size L',
            'attributes' => ['color' => 'Hitam', 'size' => 'L'],
        ]);
    }

    public function test_creating_sku_with_initial_stock_records_initial_stock_movement(): void
    {
        $this->actingAs($this->admin, 'admin');

        $sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RNJ-HTM-L-01',
            'stock' => 50,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $sku->id,
            'user_id' => $this->admin->id,
            'type' => StockMovement::TYPE_INITIAL_STOCK,
            'quantity_change' => 50,
            'stock_before' => 0,
            'stock_after' => 50,
            'notes' => 'Pencatatan stok awal saat pembuatan SKU / varian',
        ]);

        $this->assertCount(1, StockMovement::where('sku_id', $sku->id)->get());
    }

    public function test_creating_sku_with_zero_stock_does_not_record_movement(): void
    {
        $sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RNJ-HTM-L-ZERO',
            'stock' => 0,
            'is_active' => true,
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'sku_id' => $sku->id,
        ]);
    }

    public function test_updating_existing_sku_stock_directly_records_manual_adjustment(): void
    {
        $sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RNJ-HTM-L-02',
            'stock' => 100,
            'is_active' => true,
        ]);

        // Clear initial movement count
        $this->assertEquals(1, StockMovement::where('sku_id', $sku->id)->count());

        $this->actingAs($this->admin, 'admin');

        // Simulasi admin mengubah stok dari 100 menjadi 1000 via modal/form
        $sku->update(['stock' => 1000]);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $sku->id,
            'user_id' => $this->admin->id,
            'type' => StockMovement::TYPE_MANUAL_ADJUSTMENT,
            'quantity_change' => 900,
            'stock_before' => 100,
            'stock_after' => 1000,
            'notes' => 'Penyesuaian stok langsung via admin panel (Edit Varian/Produk)',
        ]);

        $this->assertEquals(2, StockMovement::where('sku_id', $sku->id)->count());
    }

    public function test_reducing_existing_sku_stock_directly_records_negative_manual_adjustment(): void
    {
        $sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RNJ-HTM-L-03',
            'stock' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin, 'admin');

        // Admin mengurangi stok dari 100 menjadi 80
        $sku->update(['stock' => 80]);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $sku->id,
            'user_id' => $this->admin->id,
            'type' => StockMovement::TYPE_MANUAL_ADJUSTMENT,
            'quantity_change' => -20,
            'stock_before' => 100,
            'stock_after' => 80,
        ]);
    }

    public function test_updating_sku_attributes_other_than_stock_does_not_record_movement(): void
    {
        $sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RNJ-HTM-L-04',
            'stock' => 50,
            'is_active' => true,
        ]);

        $initialCount = StockMovement::where('sku_id', $sku->id)->count();

        // Hanya ubah price_override dan status aktif
        $sku->update([
            'price_override' => 175000,
            'is_active' => false,
        ]);

        $this->assertEquals($initialCount, StockMovement::where('sku_id', $sku->id)->count());
    }

    public function test_official_movement_via_service_does_not_trigger_duplicate_observer_log(): void
    {
        $sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RNJ-HTM-L-05',
            'stock' => 50,
            'is_active' => true,
        ]);

        // Awalnya ada 1 initial_stock (+50)
        $this->assertEquals(1, StockMovement::where('sku_id', $sku->id)->count());

        $service = app(StockMovementService::class);

        // Mutasi resmi penjualan -10
        $service->recordMovement(
            sku: $sku,
            quantityChange: -10,
            type: StockMovement::TYPE_SALE,
            userId: $this->admin->id,
            notes: 'Penjualan resmi pesanan #ORD-001'
        );

        $sku->refresh();
        $this->assertEquals(40, $sku->stock);

        // Hanya boleh bertambah 1 log (total 2 log), yaitu initial_stock dan sale.
        // TIDAK BOLEH ada log ketiga bertipe manual_adjustment!
        $movements = StockMovement::where('sku_id', $sku->id)->get();
        $this->assertCount(2, $movements);
        $this->assertEquals([StockMovement::TYPE_INITIAL_STOCK, StockMovement::TYPE_SALE], $movements->pluck('type')->all());
    }

    public function test_variant_matrix_creation_records_initial_stock_for_all_skus(): void
    {
        $this->actingAs($this->admin, 'admin');

        $action = app(CreateVariantMatrix::class);
        $createdVariants = $action->execute($this->product, [
            [
                'color' => 'Navy',
                'sizes' => ['M', 'L'],
                'stock' => 25,
                'price_override' => null,
                'is_active' => true,
            ],
        ]);

        $this->assertCount(2, $createdVariants);

        foreach ($createdVariants as $variant) {
            $sku = $variant->skus()->first();
            $this->assertNotNull($sku);
            $this->assertEquals(25, $sku->stock);

            $this->assertDatabaseHas('stock_movements', [
                'sku_id' => $sku->id,
                'type' => StockMovement::TYPE_INITIAL_STOCK,
                'quantity_change' => 25,
                'stock_before' => 0,
                'stock_after' => 25,
                'user_id' => $this->admin->id,
            ]);
        }
    }
}
