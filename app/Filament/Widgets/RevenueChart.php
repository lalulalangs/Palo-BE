<?php

namespace App\Filament\Widgets;

use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use App\Domain\Checkout\Models\OrderItem;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Grafik penjualan 30 hari terakhir.
 *
 * ===================================================================
 *  ANGKA DIAMBIL DARI `order_items`, BUKAN DARI `orders`
 * ===================================================================
 * `order_items.subtotal` adalah snapshot harga saat beli (PRD §3.6). Kalau
 * harga produk naik bulan ini, penjualan bulan lalu tidak ikut berubah. Kalau
 * grafik memakai `orders.grand_total` atau harga katalog saat ini, laporan
 * penjualan lama ikut berubah dan tidak bisa dipercaya.
 *
 * Filter status sama seperti di `StatsOverview`: hanya order yang pembayarannya
 * sudah terverifikasi yang dihitung, supaya grafik tidak ikut naik untuk order
 * yang masih menunggu pembayaran atau ternyata dibatalkan.
 */
class RevenueChart extends ChartWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected ?string $heading = 'Penjualan 30 Hari Terakhir';

    protected ?string $description = 'Total subtotal item dari order yang pembayarannya sudah terverifikasi.';

    public static function canView(): bool
    {
        return Gate::check('viewAny', Order::class);
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getMaxHeight(): ?string
    {
        return '260px';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        // Sumbu Y menampilkan rupiah. Format angka lokal memakai
                        // pemisah ribuan gaya Indonesia.
                        'callback' => 'function (value) { return "Rp " + Number(value).toLocaleString("id-ID"); }',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $from = now()->subDays(29)->startOfDay();

        // Deret hari yang lengkap, termasuk hari tanpa penjualan. Tanpa ini
        // grafik melompati hari kosong dan magnitudenya menyesatkan.
        $days = [];

        for ($i = 0; $i < 30; $i++) {
            $day = $from->copy()->addDays($i);
            $days[$day->toDateString()] = 0;
        }

        $totals = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::paidStatusValues())
            ->where('orders.created_at', '>=', $from)
            // `subtotal` snapshot, bukan `unit_price * quantity` dihitung ulang.
            ->selectRaw('DATE(orders.created_at) as tanggal, SUM(order_items.subtotal) as total')
            ->groupBy('tanggal')
            ->pluck('total', 'tanggal');

        foreach ($totals as $date => $total) {
            $key = Carbon::parse($date)->toDateString();

            if (array_key_exists($key, $days)) {
                $days[$key] = (int) $total;
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Penjualan',
                    'data' => array_values($days),
                ],
            ],
            // Format tanggal tanpa nama bulan, supaya tidak bergantung pada
            // locale Carbon yang bisa berbeda antar server.
            'labels' => array_map(
                fn (string $date): string => Carbon::parse($date)->format('d/m'),
                array_keys($days),
            ),
        ];
    }

    /**
     * Status order yang dianggap sudah jadi penjualan.
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
}
