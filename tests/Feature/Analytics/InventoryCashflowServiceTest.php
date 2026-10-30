<?php

namespace Tests\Feature\Analytics;

use App\Models\Address;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryCashflowService;
use App\Services\Inventory\StockMovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InventoryCashflowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryCashflowService $service;

    protected Category $category;

    protected Product $product;

    protected ProductVariant $variant;

    protected Sku $skuA;

    protected Sku $skuB;

    protected User $user;

    protected Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InventoryCashflowService::class);
        Cache::flush();

        $this->category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Kaos Rinjani 3726',
            'slug' => 'kaos-rinjani-3726',
            'base_price' => 100000,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Hitam',
            'attributes' => ['color' => 'Hitam'],
        ]);

        // SKU A: fallback ke base_price (100.000), initial stock 10
        $this->skuA = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RINJANI-BLK-M',
            'price_override' => null,
            'stock' => 10,
            'is_active' => true,
        ]);

        // SKU B: override price 150.000, initial stock 5
        $this->skuB = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RINJANI-BLK-L',
            'price_override' => 150000,
            'stock' => 5,
            'is_active' => true,
        ]);

        $this->user = User::factory()->create();

        $this->address = Address::create([
            'user_id' => $this->user->id,
            'label' => 'Alamat Utama',
            'recipient_name' => 'Pendaki Rinjani',
            'phone' => '08123456789',
            'province' => 'NTB',
            'city' => 'Lombok Utara',
            'district' => 'Bayan',
            'postal_code' => '83354',
            'full_address' => 'Senaru, Bayan, NTB',
            'is_default' => true,
        ]);
    }

    public function test_kas_besar_current_valuation_calculates_active_inventory_and_caches(): void
    {
        // SKU A: 10 unit * 100.000 = 1.000.000
        // SKU B: 5 unit * 150.000 = 750.000
        // Total awal: 1.750.000, total unit: 15, skus: 2, active products: 1

        // Buat SKU nonaktif (tidak boleh dihitung)
        Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'RINJANI-INACTIVE',
            'price_override' => 200000,
            'stock' => 20,
            'is_active' => false,
        ]);

        // Buat produk soft deleted (tidak boleh dihitung)
        $deletedProduct = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Produk Terhapus',
            'slug' => 'produk-terhapus',
            'base_price' => 500000,
            'is_active' => true,
        ]);
        $deletedVariant = ProductVariant::create([
            'product_id' => $deletedProduct->id,
            'name' => 'Default',
        ]);
        Sku::create([
            'variant_id' => $deletedVariant->id,
            'sku_code' => 'DEL-PROD-SKU',
            'stock' => 50,
            'is_active' => true,
        ]);
        $deletedProduct->delete();

        $valuation = $this->service->getKasBesarCurrentValuation();

        $this->assertEquals(1750000.0, $valuation['gross_valuation']);
        $this->assertEquals(15, $valuation['total_units']);
        $this->assertEquals(2, $valuation['total_skus']);
        $this->assertEquals(1, $valuation['total_active_products']);

        // Verifikasi cache: jika stok berubah, nilai dari cache tetap sama sebelum clear
        $this->skuA->update(['stock' => 99]);
        $cachedValuation = $this->service->getKasBesarCurrentValuation();
        $this->assertEquals(1750000.0, $cachedValuation['gross_valuation']);

        Cache::forget('inventory:kas_besar_valuation');
        $refreshedValuation = $this->service->getKasBesarCurrentValuation();
        // 99 * 100.000 + 5 * 150.000 = 9.900.000 + 750.000 = 10.650.000
        $this->assertEquals(10650000.0, $refreshedValuation['gross_valuation']);
    }

    public function test_period_movement_summary_inbound_filters_strictly_and_excludes_cancellations(): void
    {
        $start = Carbon::parse('2026-10-01 00:00:00');
        $end = Carbon::parse('2026-10-31 23:59:59');

        // Bersihkan mutasi dari setUp
        StockMovement::query()->delete();

        // 1. Restock valid (+10 pcs @ 100.000 = 1.000.000)
        StockMovement::forceCreate([
            'sku_id' => $this->skuA->id,
            'type' => StockMovement::TYPE_RESTOCK,
            'quantity_change' => 10,
            'unit_price_snapshot' => 100000,
            'stock_before' => 0,
            'stock_after' => 10,
            'created_at' => '2026-10-05 10:00:00',
        ]);

        // 2. Initial stock valid (+5 pcs @ 150.000 = 750.000)
        StockMovement::forceCreate([
            'sku_id' => $this->skuB->id,
            'type' => StockMovement::TYPE_INITIAL_STOCK,
            'quantity_change' => 5,
            'unit_price_snapshot' => 150000,
            'stock_before' => 0,
            'stock_after' => 5,
            'created_at' => '2026-10-06 10:00:00',
        ]);

        // 3. Manual adjustment positif (+2 pcs @ 100.000 = 200.000)
        StockMovement::forceCreate([
            'sku_id' => $this->skuA->id,
            'type' => StockMovement::TYPE_MANUAL_ADJUSTMENT,
            'quantity_change' => 2,
            'unit_price_snapshot' => 100000,
            'stock_before' => 10,
            'stock_after' => 12,
            'created_at' => '2026-10-07 10:00:00',
        ]);

        // 4. Manual adjustment negatif (-3 pcs): WAJIB DIABAIKAN (quantity_change < 0)
        StockMovement::forceCreate([
            'sku_id' => $this->skuA->id,
            'type' => StockMovement::TYPE_MANUAL_ADJUSTMENT,
            'quantity_change' => -3,
            'unit_price_snapshot' => 100000,
            'stock_before' => 12,
            'stock_after' => 9,
            'created_at' => '2026-10-08 10:00:00',
        ]);

        // 5. Pembatalan pesanan (+4 pcs): WAJIB DIABAIKAN (bukan restock murni)
        StockMovement::forceCreate([
            'sku_id' => $this->skuA->id,
            'type' => StockMovement::TYPE_CANCELLATION,
            'quantity_change' => 4,
            'unit_price_snapshot' => 100000,
            'stock_before' => 9,
            'stock_after' => 13,
            'created_at' => '2026-10-09 10:00:00',
        ]);

        // 6. Penjualan (-5 pcs): WAJIB DIABAIKAN
        StockMovement::forceCreate([
            'sku_id' => $this->skuA->id,
            'type' => StockMovement::TYPE_SALE,
            'quantity_change' => -5,
            'unit_price_snapshot' => 100000,
            'stock_before' => 13,
            'stock_after' => 8,
            'created_at' => '2026-10-10 10:00:00',
        ]);

        // 7. Restock di luar periode (September 2026): WAJIB DIABAIKAN
        StockMovement::forceCreate([
            'sku_id' => $this->skuA->id,
            'type' => StockMovement::TYPE_RESTOCK,
            'quantity_change' => 20,
            'unit_price_snapshot' => 100000,
            'stock_before' => 0,
            'stock_after' => 20,
            'created_at' => '2026-09-25 10:00:00',
        ]);

        $summary = $this->service->getPeriodMovementSummary($start, $end);

        // Inbound yang diakui: item 1 (1.000.000) + item 2 (750.000) + item 3 (200.000) = 1.950.000
        $this->assertEquals(1950000.0, $summary['inbound_value']);
        $this->assertEquals(17, $summary['inbound_units']);
    }

    public function test_stock_movement_snapshot_and_fallback_to_effective_price(): void
    {
        $movementService = app(StockMovementService::class);

        // 1. Catat dengan explicit snapshot
        $movementWithCustomSnapshot = $movementService->recordMovement(
            sku: $this->skuA,
            quantityChange: 5,
            type: StockMovement::TYPE_RESTOCK,
            notes: 'Restock harga diskon supplier',
            unitPriceSnapshot: 85000.00
        );
        $this->assertEquals(85000.00, $movementWithCustomSnapshot->unit_price_snapshot);

        // 2. Catat tanpa snapshot (fallback ke effective_price SKU A = 100.000)
        $movementFallback = $movementService->recordMovement(
            sku: $this->skuA,
            quantityChange: 3,
            type: StockMovement::TYPE_RESTOCK,
            notes: 'Restock harga normal'
        );
        $this->assertEquals(100000.00, $movementFallback->unit_price_snapshot);

        // 3. Catat via SKU update observer (manual_adjustment)
        $this->skuB->update(['stock' => 12]); // dari 5 ke 12 (+7)
        $movementObserver = StockMovement::where('sku_id', $this->skuB->id)
            ->where('type', StockMovement::TYPE_MANUAL_ADJUSTMENT)
            ->latest('id')
            ->first();

        $this->assertNotNull($movementObserver);
        $this->assertEquals(150000.00, $movementObserver->unit_price_snapshot);
    }

    public function test_sales_and_prorated_promo_discount_allocation(): void
    {
        $start = Carbon::parse('2026-10-01 00:00:00');
        $end = Carbon::parse('2026-10-31 23:59:59');

        // Order 1: Status PAID, subtotal 300.000, discount 60.000 (net: 240.000)
        $order1 = Order::forceCreate([
            'order_number' => 'ORD-VAL-001',
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'status' => Order::STATUS_PAID,
            'subtotal' => 300000,
            'discount_amount' => 60000,
            'shipping_cost' => 10000,
            'total' => 250000,
            'shipping_recipient_name' => 'Pendaki Rinjani',
            'shipping_phone' => '08123456789',
            'shipping_full_address' => 'Senaru, Bayan, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'created_at' => '2026-10-10 14:00:00',
        ]);

        // Item 1A: subtotal 200.000 (proporsi 200k/300k = 66.67% -> diskon prorata = 40.000)
        OrderItem::create([
            'order_id' => $order1->id,
            'sku_id' => $this->skuA->id,
            'product_name_snapshot' => 'Kaos Rinjani 3726',
            'variant_name_snapshot' => 'Hitam - M',
            'sku_code_snapshot' => 'RINJANI-BLK-M',
            'price_snapshot' => 100000,
            'quantity' => 2,
            'subtotal' => 200000,
        ]);

        // Item 1B: subtotal 100.000 (proporsi 100k/300k = 33.33% -> diskon prorata = 20.000)
        OrderItem::create([
            'order_id' => $order1->id,
            'sku_id' => $this->skuB->id,
            'product_name_snapshot' => 'Kaos Rinjani 3726',
            'variant_name_snapshot' => 'Hitam - L',
            'sku_code_snapshot' => 'RINJANI-BLK-L',
            'price_snapshot' => 100000,
            'quantity' => 1,
            'subtotal' => 100000,
        ]);

        // Order 2: Status COMPLETED, Non-Promo, subtotal 100.000, discount 0
        $order2 = Order::forceCreate([
            'order_number' => 'ORD-VAL-002',
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'status' => Order::STATUS_COMPLETED,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'shipping_cost' => 10000,
            'total' => 110000,
            'shipping_recipient_name' => 'Pendaki Rinjani',
            'shipping_phone' => '08123456789',
            'shipping_full_address' => 'Senaru, Bayan, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'created_at' => '2026-10-15 10:00:00',
        ]);

        OrderItem::create([
            'order_id' => $order2->id,
            'sku_id' => $this->skuA->id,
            'product_name_snapshot' => 'Kaos Rinjani 3726',
            'variant_name_snapshot' => 'Hitam - M',
            'sku_code_snapshot' => 'RINJANI-BLK-M',
            'price_snapshot' => 100000,
            'quantity' => 1,
            'subtotal' => 100000,
        ]);

        // Order 3: Status PENDING (Belum lunas) -> Tidak boleh masuk hitungan
        $order3 = Order::forceCreate([
            'order_number' => 'ORD-VAL-003',
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 500000,
            'discount_amount' => 100000,
            'shipping_cost' => 10000,
            'total' => 410000,
            'shipping_recipient_name' => 'Pendaki Rinjani',
            'shipping_phone' => '08123456789',
            'shipping_full_address' => 'Senaru, Bayan, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'created_at' => '2026-10-18 10:00:00',
        ]);

        // Summary: Semua transaksi (all)
        $summaryAll = $this->service->getPeriodMovementSummary($start, $end, 'all');
        $this->assertEquals(400000.0, $summaryAll['gross_sales_value']);
        $this->assertEquals(60000.0, $summaryAll['total_discount_given']);
        $this->assertEquals(340000.0, $summaryAll['net_cash_realized']);
        $this->assertEquals(15.0, $summaryAll['discount_ratio_pct']); // (60.000 / 400.000) * 100

        // Summary: Hanya promo
        $summaryPromo = $this->service->getPeriodMovementSummary($start, $end, 'promo_only');
        $this->assertEquals(300000.0, $summaryPromo['gross_sales_value']);
        $this->assertEquals(60000.0, $summaryPromo['total_discount_given']);
        $this->assertEquals(240000.0, $summaryPromo['net_cash_realized']);

        // Summary: Non promo
        $summaryNonPromo = $this->service->getPeriodMovementSummary($start, $end, 'non_promo');
        $this->assertEquals(100000.0, $summaryNonPromo['gross_sales_value']);
        $this->assertEquals(0.0, $summaryNonPromo['total_discount_given']);
        $this->assertEquals(100000.0, $summaryNonPromo['net_cash_realized']);

        // Top Promo Impact Products:
        $topProducts = $this->service->getTopPromoImpactProducts($start, $end, 10);
        $this->assertCount(2, $topProducts);

        $rank1 = $topProducts->firstWhere('sku_code', 'RINJANI-BLK-M');
        $this->assertEquals(40000.0, $rank1->total_promo_subsidy);
        $this->assertEquals(3, $rank1->units_sold); // 2 dari order1 + 1 dari order2
        $this->assertEquals(300000.0, $rank1->gross_sales_value);
        $this->assertEquals(260000.0, $rank1->net_cash_realized);

        $rank2 = $topProducts->firstWhere('sku_code', 'RINJANI-BLK-L');
        $this->assertEquals(20000.0, $rank2->total_promo_subsidy);
        $this->assertEquals(1, $rank2->units_sold);
        $this->assertEquals(100000.0, $rank2->gross_sales_value);
        $this->assertEquals(80000.0, $rank2->net_cash_realized);
    }

    public function test_daily_cashflow_trend_continuity_and_zero_fill(): void
    {
        $start = Carbon::parse('2026-10-01');
        $end = Carbon::parse('2026-10-03');

        // Hari 1 (2026-10-01): Ada Inbound 500.000 & Sales 200.000 (net 180.000)
        StockMovement::forceCreate([
            'sku_id' => $this->skuA->id,
            'type' => StockMovement::TYPE_RESTOCK,
            'quantity_change' => 5,
            'unit_price_snapshot' => 100000,
            'stock_before' => 0,
            'stock_after' => 5,
            'created_at' => '2026-10-01 09:00:00',
        ]);

        Order::forceCreate([
            'order_number' => 'ORD-DAY-1',
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'status' => Order::STATUS_PAID,
            'subtotal' => 200000,
            'discount_amount' => 20000,
            'shipping_cost' => 10000,
            'total' => 190000,
            'shipping_recipient_name' => 'Pendaki Rinjani',
            'shipping_phone' => '08123456789',
            'shipping_full_address' => 'Senaru, Bayan, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'created_at' => '2026-10-01 15:00:00',
        ]);

        // Hari 2 (2026-10-02): Tidak ada transaksi sama sekali (wajib 0)

        // Hari 3 (2026-10-03): Ada Sales 100.000 (net 100.000)
        Order::forceCreate([
            'order_number' => 'ORD-DAY-3',
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'status' => Order::STATUS_COMPLETED,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'shipping_cost' => 10000,
            'total' => 110000,
            'shipping_recipient_name' => 'Pendaki Rinjani',
            'shipping_phone' => '08123456789',
            'shipping_full_address' => 'Senaru, Bayan, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'created_at' => '2026-10-03 11:00:00',
        ]);

        $trend = $this->service->getDailyCashflowTrend($start, $end);

        $this->assertEquals(['2026-10-01', '2026-10-02', '2026-10-03'], $trend['labels']);
        $this->assertEquals([500000.0, 0.0, 0.0], $trend['inbound_series']);
        $this->assertEquals([200000.0, 0.0, 100000.0], $trend['gross_sales_series']);
        $this->assertEquals([180000.0, 0.0, 100000.0], $trend['net_cash_series']);
    }
}
