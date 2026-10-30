<?php

namespace App\Filament\Widgets;

use App\Services\Inventory\InventoryCashflowService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KasBesarStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Ringkasan Kas dan Arus Barang';

    protected ?string $description = 'Pemantauan modal inventaris aktif dan mutasi perputaran dana toko.';

    public static function canView(): bool
    {
        return (bool) Filament::auth()->user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected function getStats(): array
    {
        $start = filled($this->pageFilters['startDate'] ?? null)
            ? Carbon::parse($this->pageFilters['startDate'])
            : now()->startOfMonth();

        $end = filled($this->pageFilters['endDate'] ?? null)
            ? Carbon::parse($this->pageFilters['endDate'])
            : now()->endOfDay();

        $promoStatus = (string) ($this->pageFilters['promoStatus'] ?? 'all');

        $service = app(InventoryCashflowService::class);
        $valuation = $service->getKasBesarCurrentValuation();
        $summary = $service->getPeriodMovementSummary($start, $end, $promoStatus);
        $trend = $service->getDailyCashflowTrend($start, $end, $promoStatus);

        $discountTrend = array_map(
            fn ($gross, $net) => max(0.0, (float) $gross - (float) $net),
            $trend['gross_sales_series'],
            $trend['net_cash_series']
        );

        return [
            Stat::make(
                'Nilai Stok Gudang',
                'Rp ' . number_format($valuation['gross_valuation'], 0, ',', '.')
            )
                ->description(number_format($valuation['total_units'], 0, ',', '.') . ' unit dari ' . $valuation['total_skus'] . ' varian produk aktif')
                ->descriptionIcon('heroicon-m-archive-box', IconPosition::Before)
                ->color('gray'),

            Stat::make(
                'Nilai Belanja Barang',
                'Rp ' . number_format($summary['inbound_value'], 0, ',', '.')
            )
                ->description('Penambahan ' . number_format($summary['inbound_units'], 0, ',', '.') . ' unit stok masuk periode ini')
                ->descriptionIcon('heroicon-m-truck', IconPosition::Before)
                ->chart($trend['inbound_series'])
                ->color('info'),

            Stat::make(
                'Penerimaan Kas Bersih',
                'Rp ' . number_format($summary['net_cash_realized'], 0, ',', '.')
            )
                ->description('Dari total omzet kotor Rp ' . number_format($summary['gross_sales_value'], 0, ',', '.'))
                ->descriptionIcon('heroicon-m-banknotes', IconPosition::Before)
                ->chart($trend['net_cash_series'])
                ->color('success')
                ->extraAttributes([
                    'class' => 'ring-2 ring-emerald-500/30 border-emerald-500/50 bg-emerald-50/20 dark:bg-emerald-950/20',
                ]),

            Stat::make(
                'Potongan Diskon dan Promo',
                'Rp ' . number_format($summary['total_discount_given'], 0, ',', '.')
            )
                ->description('Porsi ' . str_replace('.', ',', (string) $summary['discount_ratio_pct']) . '% dari total penjualan kotor')
                ->descriptionIcon('heroicon-m-tag', IconPosition::Before)
                ->chart($discountTrend)
                ->color('warning'),
        ];
    }
}
