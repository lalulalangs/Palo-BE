<?php

namespace App\Filament\Widgets;

use App\Services\Inventory\InventoryCashflowService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class StockCashflowTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected ?string $heading = 'Tren Arus Barang dan Penerimaan Kas';

    protected ?string $description = 'Perbandingan harian nilai belanja barang masuk, omzet kotor, dan kas bersih yang diterima toko.';

    protected ?string $maxHeight = '320px';

    public static function canView(): bool
    {
        return (bool) Filament::auth()->user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
        parent::mount();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $start = filled($this->pageFilters['startDate'] ?? null)
            ? Carbon::parse($this->pageFilters['startDate'])
            : now()->startOfMonth();

        $end = filled($this->pageFilters['endDate'] ?? null)
            ? Carbon::parse($this->pageFilters['endDate'])
            : now()->endOfDay();

        $promoStatus = (string) ($this->pageFilters['promoStatus'] ?? 'all');

        $trend = app(InventoryCashflowService::class)->getDailyCashflowTrend($start, $end, $promoStatus);

        return [
            'datasets' => [
                [
                    'label' => 'Belanja Barang Masuk',
                    'data' => $trend['inbound_series'],
                    'borderColor' => '#2a6f97',
                    'backgroundColor' => 'rgba(42, 111, 151, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Omzet Penjualan Kotor',
                    'data' => $trend['gross_sales_series'],
                    'borderColor' => '#d96b27',
                    'backgroundColor' => 'rgba(217, 107, 39, 0.05)',
                    'borderDash' => [5, 5],
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Penerimaan Kas Bersih',
                    'data' => $trend['net_cash_series'],
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $trend['labels'],
        ];
    }
}
