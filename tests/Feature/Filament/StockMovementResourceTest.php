<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockMovementResourceTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
            'name' => 'Admin Gudang Senaru',
        ]);

        $category = Category::create(['name' => 'Jaket', 'slug' => 'jaket']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Windbreaker',
            'slug' => 'jaket-windbreaker',
            'base_price' => 250000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Navy - XL',
            'attributes' => ['color' => 'Navy', 'size' => 'XL'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'JKT-WND-NVY-XL',
            'stock' => 10,
        ]);
    }

    public function test_can_render_stock_movements_list_page(): void
    {
        $movement = StockMovement::create([
            'sku_id' => $this->sku->id,
            'user_id' => $this->admin->id,
            'type' => StockMovement::TYPE_RESTOCK,
            'quantity_change' => 10,
            'stock_before' => 0,
            'stock_after' => 10,
            'notes' => 'Stok awal gudang',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockMovements::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$movement]);
    }

    public function test_quick_adjustment_action_creates_restock_and_updates_sku_stock(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockMovements::class)
            ->callAction('quick_adjustment', [
                'sku_id' => $this->sku->id,
                'type' => StockMovement::TYPE_RESTOCK,
                'quantity_change' => 15,
                'notes' => 'Restock cepat dari konveksi',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Mutasi stok berhasil dicatat');

        $this->sku->refresh();
        $this->assertEquals(25, $this->sku->stock);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'user_id' => $this->admin->id,
            'type' => StockMovement::TYPE_RESTOCK,
            'quantity_change' => 15,
            'stock_before' => 10,
            'stock_after' => 25,
            'notes' => 'Restock cepat dari konveksi',
        ]);
    }

    public function test_quick_adjustment_prevents_excessive_decrement(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockMovements::class)
            ->callAction('quick_adjustment', [
                'sku_id' => $this->sku->id,
                'type' => StockMovement::TYPE_MANUAL_ADJUSTMENT,
                'quantity_change' => -20, // Stok hanya 10
                'notes' => 'Koreksi salah hitung',
            ])
            ->assertNotified('Gagal mencatat mutasi');

        $this->sku->refresh();
        $this->assertEquals(10, $this->sku->stock); // Tidak berubah
    }
}
