<?php

namespace Tests\Feature\Filament;

use App\Enums\OrderStatus;
use App\Filament\Pages\ManageShippingSettings;
use App\Filament\Pages\ManageSocialMediaSettings;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

class FilamentUxRefreshTest extends TestCase
{
    use FakesRajaOngkir;
    use RefreshDatabase;

    protected AdminUser $admin;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin.ux@palorinjani.com',
            'name' => 'Admin UX',
        ]);

        $category = Category::create(['name' => 'Aksesoris', 'slug' => 'aksesoris']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Topi Rimba UX',
            'slug' => 'topi-rimba-ux',
            'base_price' => 85000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Olive - All Size',
            'attributes' => ['color' => 'Olive'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'ACC-UX-001',
            'stock' => 20,
        ]);
    }

    public function test_edit_stock_opname_apply_refreshes_form_and_hides_draft_actions_in_one_click(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-UX-001',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku_id' => $this->sku->id,
            'system_stock' => 20,
            'physical_stock' => 17,
            'difference' => -3,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditStockOpname::class, ['record' => $opname->getRouteKey()])
            ->assertActionVisible('apply_opname')
            ->assertActionVisible('cancel_opname')
            ->assertSee('Simpan Perubahan Draft')
            ->callAction('apply_opname')
            ->assertNotified('Penyesuaian stok opname berhasil diterapkan')
            ->assertFormSet([
                'status' => 'Selesai (Terkunci)',
            ])
            ->assertActionHidden('apply_opname')
            ->assertActionHidden('cancel_opname')
            ->assertDontSee('Simpan Perubahan Draft');
    }

    public function test_stock_opname_cancel_action_succeeds_and_redirects_to_index(): void
    {
        $opname = StockOpname::create([
            'opname_number' => 'OPN-UX-002',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditStockOpname::class, ['record' => $opname->getRouteKey()])
            ->assertActionVisible('cancel_opname')
            ->callAction('cancel_opname')
            ->assertNotified('Sesi opname berhasil dibatalkan')
            ->assertRedirect();

        $this->assertSame(StockOpname::STATUS_CANCELLED, $opname->fresh()->status);

        $tableOpname = StockOpname::create([
            'opname_number' => 'OPN-UX-003',
            'user_id' => $this->admin->id,
            'status' => StockOpname::STATUS_DRAFT,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockOpnames::class)
            ->callTableAction('cancel_opname', $tableOpname)
            ->assertNotified('Sesi opname berhasil dibatalkan');

        $this->assertSame(StockOpname::STATUS_CANCELLED, $tableOpname->fresh()->status);
    }

    public function test_view_order_actions_refresh_record_and_update_action_visibility_in_one_click(): void
    {
        $buyer = User::factory()->create();

        $order = Order::create([
            'order_number' => 'UJN-UX-001',
            'user_id' => $buyer->id,
            'status' => OrderStatus::Paid,
            'subtotal' => 85000,
            'discount_amount' => 0,
            'shipping_cost' => 15000,
            'total' => 100000,
            'shipping_recipient_name' => 'Budi Santoso',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => 'Jl. Raya Senaru No. 1, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'payment_method' => 'online',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Topi Rimba UX',
            'variant_name_snapshot' => 'Olive - All Size',
            'sku_code_snapshot' => 'ACC-UX-001',
            'price_snapshot' => 85000,
            'quantity' => 1,
            'subtotal' => 85000,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('mark_as_processing')
            ->assertActionHidden('mark_as_shipped')
            ->callAction('mark_as_processing')
            ->assertNotified("Pesanan {$order->order_number} sedang disiapkan")
            ->assertActionHidden('mark_as_processing')
            ->assertActionVisible('mark_as_shipped')
            ->callAction('mark_as_shipped', [
                'tracking_number' => 'JNE-UX-999',
                'note' => 'Dikirim sore',
            ])
            ->assertNotified("Pesanan {$order->order_number} berhasil dikirim")
            ->assertActionHidden('mark_as_shipped')
            ->assertActionVisible('update_tracking')
            ->assertActionVisible('mark_as_completed')
            ->assertSee('JNE-UX-999');
    }

    public function test_settings_pages_refill_normalized_data_after_save(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ManageShippingSettings::class)
            ->fillForm([
                'shipping_origin_subdistrict_id' => 746,
                'shipping_origin_label' => '  Bayan, Lombok Utara, NTB, 83354  ',
                'shipping_enabled_couriers' => ['jne', 'jnt'],
                'hide_cargo_services' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet([
                'shipping_origin_subdistrict_id' => '746',
                'shipping_origin_label' => 'Bayan, Lombok Utara, NTB, 83354',
                'shipping_enabled_couriers' => ['jne', 'jnt'],
                'hide_cargo_services' => true,
            ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ManageSocialMediaSettings::class)
            ->fillForm([
                'instagram_handle' => '  @palorinjani  ',
                'whatsapp_number' => '081234567890',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet([
                'instagram_handle' => '@palorinjani',
                'whatsapp_number' => '081234567890',
            ]);
    }

    public function test_stock_movement_quick_adjustment_rejects_zero_with_custom_message(): void
    {
        $initialCount = StockMovement::where('sku_id', $this->sku->id)->count();

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListStockMovements::class)
            ->callAction('quick_adjustment', [
                'sku_id' => $this->sku->id,
                'type' => StockMovement::TYPE_MANUAL_ADJUSTMENT,
                'quantity_change' => 0,
                'notes' => 'Tes nol',
            ])
            ->assertHasActionErrors(['quantity_change' => 'not_in']);

        $this->assertSame($initialCount, StockMovement::where('sku_id', $this->sku->id)->count());
    }
}
