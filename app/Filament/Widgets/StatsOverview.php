<?php

namespace App\Filament\Widgets;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Sku;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use App\Domain\Shared\Models\AppSetting;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * Ringkasan operasional di dashboard.
 *
 * ===================================================================
 *  DEFINISI "PENDAPATAN" DI SINI TIDAK BISA DISEBELAKAN
 * ===================================================================
 * Yang dihitung adalah `SUM(grand_total)` untuk order yang sudah BAYAR,
 * yaitu status `dibayar`, `diproses`, `dikirim`, atau `selesai`.
 *
 * Kenapa tidak semua order:
 *   - `menunggu_pembayaran` belum jadi uang. Menghitungnya membuat revenue
 *     naik untuk order yang bisa saja dibatalkan atau expired.
 *   - `expired` dan `dibatalkan` justru mengembalikan stok, jadi termasuk
 *     penjualan batal.
 *
 * PENTING: angka ini dipakai untuk operasional, BUKAN untuk KPI resmi. KPI
 * §8 menghitung "guest-to-WhatsApp Conversion Rate" dari
 * `guest_checkout_logs`, bukan dari `orders` (lihat ARSITEKTUR.md §7).
 */
class StatsOverview extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    /**
     * Hanya admin. Tanpa ini, widget dievaluasi untuk semua user yang somehow
     * sampai ke dashboard, sehingga angka penjualan tampil di layar yang
     * seharusnya tidak bisa diakses.
     */
    public static function canView(): bool
    {
        return Gate::check('viewAny', Order::class);
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $ordersToday = Order::query()
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $ordersYesterday = Order::query()
            ->whereDate('created_at', now()->subDay()->toDateString())
            ->count();

        $paidStatuses = self::paidStatusValues();

        $revenueToday = (int) Order::query()
            ->whereIn('status', $paidStatuses)
            ->whereDate('created_at', now()->toDateString())
            ->sum('grand_total');

        $revenueYesterday = (int) Order::query()
            ->whereIn('status', $paidStatuses)
            ->whereDate('created_at', now()->subDay()->toDateString())
            ->sum('grand_total');

        $activeProducts = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->count();

        // Ambang batas stok menipis. Angka ini keputusan operasional, jadi
        // dibaca dari app_settings supaya bisa diubah tanpa deploy.
        $threshold = (int) AppSetting::get('inventory.low_stock_threshold', 5);

        $lowStockCount = Sku::query()
            ->where('is_active', true)
            ->where('on_hand', '<=', $threshold)
            ->count();

        return [
            Stat::make('Total Pesanan Hari Ini', (string) number_format($ordersToday, 0, ',', '.'))
                ->description($this->deltaDescription($ordersToday, $ordersYesterday, 'pesanan'))
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color($ordersToday < $ordersYesterday ? 'warning' : 'success')
                ->url(OrderResource::getUrl('index', [
                    'tableFilters' => ['periode' => ['values' => ['today']]],
                ])),

            Stat::make('Pendapatan Hari Ini', 'Rp '.number_format($revenueToday, 0, ',', '.'))
                ->description($this->deltaDescription($revenueToday, $revenueYesterday, ''))
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('primary'),

            Stat::make('Produk Aktif', (string) number_format($activeProducts, 0, ',', '.'))
                ->description('Tampil di storefront (PRD §6A).')
                ->descriptionIcon('heroicon-o-cube')
                ->color('info')
                ->url(ProductResource::getUrl('index', [
                    'tableFilters' => ['status' => ['values' => [ProductStatus::Active->value]]],
                ])),

            Stat::make('SKU Perlu Restok', (string) number_format($lowStockCount, 0, ',', '.'))
                ->description('Stok fisik di atau bawah ambang batas.')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($lowStockCount > 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * Status order yang dianggap sudah jadi pendapatan.
     *
     * @return array<int, string>
     */
    public static function paidStatusValues(): array
    {
        return [
            OrderStatus::Dibayar->value,
            OrderStatus::Diproses->value,
            OrderStatus::Dikirim->value,
            OrderStatus::Selesai->value,
        ];
    }

    /**
     * Bandingkan hari ini dengan kemarin.
     *
     * Kemarin dipakai sebagai pembanding karena belum ada data historis lain di
     * sistem yang baru berjalan. Nilai kemarin yang nol hanya menghasilkan
     * "tidak ada pembanding", bukan "naik tak terbatas".
     *
     * @param  string  $unit  Kata satuan, string kosong bila angka rupiah.
     */
    private function deltaDescription(int|float $current, int|float $previous, string $unit): string
    {
        if ($previous <= 0) {
            return 'Belum ada pembanding kemarin.';
        }

        $diff = $current - $previous;
        $percent = round(abs($diff) / $previous * 100);
        $direction = $diff >= 0 ? 'naik' : 'turun';

        return sprintf(
            '%s %d%% dibanding kemarin%s',
            $direction,
            $percent,
            $unit === '' ? '' : ' '.$unit,
        );
    }
}
