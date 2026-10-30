<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\KasBesarStatsOverview;
use App\Filament\Widgets\PromoImpactTableWidget;
use App\Filament\Widgets\ShippingQuotaWidget;
use App\Filament\Widgets\StockCashflowTrendChart;
use App\Models\Address;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryCashflowDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $superAdmin;

    protected AdminUser $staffAdmin;

    protected Product $product;

    protected Sku $sku;

    protected User $customer;

    protected Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->superAdmin = AdminUser::factory()->superAdmin()->create([
            'email' => 'superadmin@palorinjani.com',
        ]);

        $this->staffAdmin = AdminUser::factory()->create([
            'email' => 'staff@palorinjani.com',
        ]);

        $category = Category::create([
            'name' => 'Tenda Gunung',
            'slug' => 'tenda-gunung',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Tenda Rinjani Ultralight 2P',
            'slug' => 'tenda-rinjani-ultralight-2p',
            'base_price' => 500000,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Standard Hijau',
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'TND-RINJ-2P-GRN',
            'stock' => 10,
            'price_override' => null,
            'is_active' => true,
        ]);

        $this->customer = User::factory()->create();
        $this->address = Address::create([
            'user_id' => $this->customer->id,
            'label' => 'Alamat Utama',
            'recipient_name' => 'Pendaki Lawu',
            'phone' => '081234567890',
            'province' => 'NTB',
            'city' => 'Lombok Utara',
            'district' => 'Bayan',
            'postal_code' => '83354',
            'full_address' => 'Desa Senaru KM 5',
            'is_default' => true,
        ]);
    }

    public function test_superadmin_can_access_dashboard_and_see_filters_and_widgets(): void
    {
        $this->actingAs($this->superAdmin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('KasBesarStatsOverview')
            ->assertSee('StockCashflowTrendChart')
            ->assertSee('PromoImpactTableWidget')
            ->assertSee('Tanggal Mulai')
            ->assertSee('Tanggal Selesai')
            ->assertSee('Status Promo');
    }

    public function test_kas_besar_stats_overview_renders_data_for_superadmin(): void
    {
        // Mutasi restock 5 unit @ 500.000 = Rp 2.500.000
        app(StockMovementService::class)->recordMovement(
            sku: $this->sku,
            quantityChange: 5,
            type: StockMovement::TYPE_RESTOCK,
            notes: 'Restock Supplier'
        );

        // Order lunas: 2 unit @ 500.000 = Rp 1.000.000, diskon Rp 150.000, net Rp 850.000
        $order = Order::forceCreate([
            'order_number' => 'PLR-TEST-001',
            'user_id' => $this->customer->id,
            'address_id' => $this->address->id,
            'status' => Order::STATUS_PAID,
            'subtotal' => 1000000,
            'shipping_cost' => 20000,
            'discount_amount' => 150000,
            'total' => 870000,
            'created_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'quantity' => 2,
            'price_snapshot' => 500000,
            'subtotal' => 1000000,
            'product_name_snapshot' => $this->product->name,
            'variant_name_snapshot' => 'Standard Hijau',
            'sku_code_snapshot' => $this->sku->sku_code,
        ]);

        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(KasBesarStatsOverview::class)
            ->assertSuccessful()
            ->assertSee('Total Kas Besar')
            ->assertSee('Arus Barang Masuk')
            ->assertSee('Arus Barang Keluar Bersih')
            ->assertSee('Total Subsidi Promo / Diskon')
            ->assertSee('Rp 850.000') // Net cash realized
            ->assertSee('Rp 150.000'); // Promo subsidy
    }

    public function test_stock_cashflow_trend_chart_renders_for_superadmin(): void
    {
        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(StockCashflowTrendChart::class)
            ->assertSuccessful()
            ->assertSee('Tren Arus Kas & Nilai Mutasi Barang');
    }

    public function test_promo_impact_table_widget_renders_top_discounted_products(): void
    {
        $order = Order::forceCreate([
            'order_number' => 'PLR-TEST-002',
            'user_id' => $this->customer->id,
            'address_id' => $this->address->id,
            'status' => Order::STATUS_COMPLETED,
            'subtotal' => 500000,
            'shipping_cost' => 0,
            'discount_amount' => 100000,
            'total' => 400000,
            'created_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'quantity' => 1,
            'price_snapshot' => 500000,
            'subtotal' => 500000,
            'product_name_snapshot' => $this->product->name,
            'variant_name_snapshot' => 'Standard Hijau',
            'sku_code_snapshot' => $this->sku->sku_code,
        ]);

        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(PromoImpactTableWidget::class)
            ->assertSuccessful()
            ->assertSee('Ranking Produk Beban Subsidi Promo Terbesar')
            ->assertSee('TND-RINJ-2P-GRN')
            ->assertSee('Tenda Rinjani Ultralight 2P')
            ->assertSee('Rp 100.000')
            ->assertSee('Rp 400.000');
    }

    public function test_non_superadmin_staff_cannot_see_cashflow_widgets_or_filters(): void
    {
        $this->actingAs($this->staffAdmin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('KasBesarStatsOverview')
            ->assertDontSee('StockCashflowTrendChart')
            ->assertDontSee('PromoImpactTableWidget')
            ->assertDontSee('Tanggal Mulai')
            ->assertDontSee('Tanggal Selesai')
            ->assertDontSee('Status Promo');
    }

    public function test_direct_widget_access_is_forbidden_for_non_superadmin(): void
    {
        Livewire::actingAs($this->staffAdmin, 'admin')
            ->test(KasBesarStatsOverview::class)
            ->assertForbidden();

        Livewire::actingAs($this->staffAdmin, 'admin')
            ->test(StockCashflowTrendChart::class)
            ->assertForbidden();

        Livewire::actingAs($this->staffAdmin, 'admin')
            ->test(PromoImpactTableWidget::class)
            ->assertForbidden();
    }

    public function test_shipping_quota_widget_is_not_displayed_on_admin_dashboard(): void
    {
        $this->actingAs($this->superAdmin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('ShippingQuotaWidget');

        $this->actingAs($this->staffAdmin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('ShippingQuotaWidget');
    }
}
