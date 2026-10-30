<?php

namespace App\Services\Inventory;

use App\Models\Order;
use App\Models\StockMovement;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryCashflowService
{
    /**
     * Menghitung nilai "Kas Besar" (Gross Active Inventory Valuation) saat ini.
     *
     * @return array{
     *     gross_valuation: float,
     *     total_units: int,
     *     total_skus: int,
     *     total_active_products: int
     * }
     */
    public function getKasBesarCurrentValuation(): array
    {
        return Cache::remember('inventory:kas_besar_valuation', 300, function (): array {
            $result = DB::table('skus')
                ->join('product_variants', 'skus.variant_id', '=', 'product_variants.id')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->where('skus.is_active', true)
                ->where('products.is_active', true)
                ->whereNull('products.deleted_at')
                ->selectRaw('
                    COALESCE(SUM(skus.stock * COALESCE(skus.price_override, products.base_price, 0)), 0) as gross_valuation,
                    COALESCE(SUM(skus.stock), 0) as total_units,
                    COUNT(skus.id) as total_skus,
                    COUNT(DISTINCT products.id) as total_active_products
                ')
                ->first();

            return [
                'gross_valuation' => (float) ($result->gross_valuation ?? 0),
                'total_units' => (int) ($result->total_units ?? 0),
                'total_skus' => (int) ($result->total_skus ?? 0),
                'total_active_products' => (int) ($result->total_active_products ?? 0),
            ];
        });
    }

    /**
     * Menghitung ringkasan pergerakan barang & arus kas dalam periode tertentu.
     *
     * @return array{
     *     inbound_value: float,
     *     inbound_units: int,
     *     gross_sales_value: float,
     *     net_cash_realized: float,
     *     total_discount_given: float,
     *     discount_ratio_pct: float
     * }
     */
    public function getPeriodMovementSummary(
        CarbonInterface $start,
        CarbonInterface $end,
        string $promoFilter = 'all'
    ): array {
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->endOfDay();

        $inboundTypes = [
            StockMovement::TYPE_RESTOCK,
            StockMovement::TYPE_INITIAL_STOCK,
            StockMovement::TYPE_MANUAL_ADJUSTMENT,
        ];

        $inboundStats = DB::table('stock_movements')
            ->join('skus', 'stock_movements.sku_id', '=', 'skus.id')
            ->join('product_variants', 'skus.variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereIn('stock_movements.type', $inboundTypes)
            ->where('stock_movements.quantity_change', '>', 0)
            ->whereBetween('stock_movements.created_at', [$startDate, $endDate])
            ->selectRaw('
                COALESCE(SUM(stock_movements.quantity_change * COALESCE(stock_movements.unit_price_snapshot, skus.price_override, products.base_price, 0)), 0) as inbound_value,
                COALESCE(SUM(stock_movements.quantity_change), 0) as inbound_units
            ')
            ->first();

        $orderQuery = DB::table('orders')
            ->whereIn('status', [
                Order::STATUS_PAID,
                Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED,
                Order::STATUS_COMPLETED,
            ])
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($promoFilter === 'promo_only') {
            $orderQuery->where(function ($q): void {
                $q->where('discount_amount', '>', 0)
                    ->orWhereNotNull('voucher_id');
            });
        } elseif ($promoFilter === 'non_promo') {
            $orderQuery->where(function ($q): void {
                $q->where(function ($sub): void {
                    $sub->whereNull('discount_amount')
                        ->orWhere('discount_amount', '<=', 0);
                })->whereNull('voucher_id');
            });
        }

        $salesStats = $orderQuery
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) as gross_sales_value,
                COALESCE(SUM(discount_amount), 0) as total_discount_given,
                COALESCE(SUM(subtotal - discount_amount), 0) as net_cash_realized
            ')
            ->first();

        $grossSalesValue = (float) ($salesStats->gross_sales_value ?? 0);
        $totalDiscountGiven = (float) ($salesStats->total_discount_given ?? 0);
        $netCashRealized = (float) ($salesStats->net_cash_realized ?? 0);

        $discountRatioPct = $grossSalesValue > 0
            ? round(($totalDiscountGiven / $grossSalesValue) * 100, 2)
            : 0.0;

        return [
            'inbound_value' => (float) ($inboundStats->inbound_value ?? 0),
            'inbound_units' => (int) ($inboundStats->inbound_units ?? 0),
            'gross_sales_value' => $grossSalesValue,
            'net_cash_realized' => $netCashRealized,
            'total_discount_given' => $totalDiscountGiven,
            'discount_ratio_pct' => $discountRatioPct,
        ];
    }

    /**
     * Menghasilkan tren arus kas dan pergerakan barang harian yang kontinu (zero-fill).
     *
     * @return array{
     *     labels: array<int, string>,
     *     inbound_series: array<int, float>,
     *     gross_sales_series: array<int, float>,
     *     net_cash_series: array<int, float>
     * }
     */
    public function getDailyCashflowTrend(
        CarbonInterface $start,
        CarbonInterface $end,
        string $promoFilter = 'all'
    ): array {
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->endOfDay();

        $period = CarbonPeriod::create($startDate, '1 day', $endDate->copy()->startOfDay());
        $dates = [];
        foreach ($period as $date) {
            $dates[] = $date->format('Y-m-d');
        }

        $isPgsql = DB::getDriverName() === 'pgsql';

        $inboundDateExpr = $isPgsql
            ? "TO_CHAR(DATE_TRUNC('day', (stock_movements.created_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Makassar'), 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', stock_movements.created_at)";

        $inboundRows = DB::table('stock_movements')
            ->join('skus', 'stock_movements.sku_id', '=', 'skus.id')
            ->join('product_variants', 'skus.variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->whereIn('stock_movements.type', [
                StockMovement::TYPE_RESTOCK,
                StockMovement::TYPE_INITIAL_STOCK,
                StockMovement::TYPE_MANUAL_ADJUSTMENT,
            ])
            ->where('stock_movements.quantity_change', '>', 0)
            ->whereBetween('stock_movements.created_at', [$startDate, $endDate])
            ->selectRaw("
                {$inboundDateExpr} as date_key,
                SUM(stock_movements.quantity_change * COALESCE(stock_movements.unit_price_snapshot, skus.price_override, products.base_price, 0)) as total_inbound
            ")
            ->groupBy('date_key')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->date_key, 0, 10));

        $orderDateExpr = $isPgsql
            ? "TO_CHAR(DATE_TRUNC('day', (created_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Makassar'), 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', created_at)";

        $orderQuery = DB::table('orders')
            ->whereIn('status', [
                Order::STATUS_PAID,
                Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED,
                Order::STATUS_COMPLETED,
            ])
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($promoFilter === 'promo_only') {
            $orderQuery->where(function ($q): void {
                $q->where('discount_amount', '>', 0)
                    ->orWhereNotNull('voucher_id');
            });
        } elseif ($promoFilter === 'non_promo') {
            $orderQuery->where(function ($q): void {
                $q->where(function ($sub): void {
                    $sub->whereNull('discount_amount')
                        ->orWhere('discount_amount', '<=', 0);
                })->whereNull('voucher_id');
            });
        }

        $salesRows = $orderQuery
            ->selectRaw("
                {$orderDateExpr} as date_key,
                SUM(subtotal) as gross_sales,
                SUM(subtotal - discount_amount) as net_cash
            ")
            ->groupBy('date_key')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->date_key, 0, 10));

        $inboundSeries = [];
        $grossSalesSeries = [];
        $netCashSeries = [];

        foreach ($dates as $date) {
            $inboundRow = $inboundRows->get($date);
            $salesRow = $salesRows->get($date);

            $inboundSeries[] = (float) ($inboundRow?->total_inbound ?? 0);
            $grossSalesSeries[] = (float) ($salesRow?->gross_sales ?? 0);
            $netCashSeries[] = (float) ($salesRow?->net_cash ?? 0);
        }

        return [
            'labels' => $dates,
            'inbound_series' => $inboundSeries,
            'gross_sales_series' => $grossSalesSeries,
            'net_cash_series' => $netCashSeries,
        ];
    }

    /**
     * Menghasilkan ranking produk dengan dampak subsidi promo terbesar (prorata).
     *
     * @return Collection<int, object>
     */
    public function getTopPromoImpactProducts(
        CarbonInterface $start,
        CarbonInterface $end,
        int $limit = 10
    ): Collection {
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->endOfDay();

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', [
                Order::STATUS_PAID,
                Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED,
                Order::STATUS_COMPLETED,
            ])
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->selectRaw("
                order_items.sku_code_snapshot as sku_code,
                order_items.product_name_snapshot as product_name,
                order_items.variant_name_snapshot as variant_name,
                SUM(order_items.quantity) as units_sold,
                SUM(order_items.subtotal) as gross_sales_value,
                SUM(ROUND(COALESCE((order_items.subtotal * 1.0 / NULLIF(orders.subtotal, 0)) * orders.discount_amount, 0), 2)) as total_promo_subsidy,
                SUM(order_items.subtotal - ROUND(COALESCE((order_items.subtotal * 1.0 / NULLIF(orders.subtotal, 0)) * orders.discount_amount, 0), 2)) as net_cash_realized
            ")
            ->groupBy(
                'order_items.sku_code_snapshot',
                'order_items.product_name_snapshot',
                'order_items.variant_name_snapshot'
            )
            ->orderByDesc('total_promo_subsidy')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (object) [
                'sku_code' => $row->sku_code,
                'product_name' => $row->product_name,
                'variant_name' => $row->variant_name,
                'units_sold' => (int) $row->units_sold,
                'gross_sales_value' => (float) $row->gross_sales_value,
                'total_promo_subsidy' => (float) $row->total_promo_subsidy,
                'net_cash_realized' => (float) $row->net_cash_realized,
            ]);
    }
}
