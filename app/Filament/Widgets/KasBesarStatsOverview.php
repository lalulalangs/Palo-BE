<?php

namespace App\Filament\Widgets;

use App\Services\Inventory\InventoryCashflowService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KasBesarStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

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

        return [
            Stat::make(
                'Total Kas Besar',
                'Rp ' . number_format($valuation['gross_valuation'], 0, ',', '.')
            )
                ->description(number_format($valuation['total_units'], 0, ',', '.') . " unit fisik ({$valuation['total_skus']} SKU)")
                ->color('success'),

            Stat::make(
                'Arus Barang Masuk',
                'Rp ' . number_format($summary['inbound_value'], 0, ',', '.')
            )
                ->description('+' . number_format($summary['inbound_units'], 0, ',', '.') . ' unit masuk periode ini')
                ->color('info'),

            Stat::make(
                'Arus Barang Keluar Bersih',
                'Rp ' . number_format($summary['net_cash_realized'], 0, ',', '.')
            )
                ->description('Kas riil diterima (Gross: Rp ' . number_format($summary['gross_sales_value'], 0, ',', '.') . ')')
                ->color('primary'),

            Stat::make(
                'Total Subsidi Promo / Diskon',
                'Rp ' . number_format($summary['total_discount_given'], 0, ',', '.')
            )
                ->description("Beban subsidi diskon ({$summary['discount_ratio_pct']}% dari gross)")
                ->color($summary['total_discount_given'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
