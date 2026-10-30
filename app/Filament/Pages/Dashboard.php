<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\KasBesarStatsOverview;
use App\Filament\Widgets\PromoImpactTableWidget;
use App\Filament\Widgets\StockCashflowTrendChart;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Layout Plan:
 * ===================================================================================
 * ROW 0: Header Filters Form (Rentang Tanggal & Filter Status Promo) - Superadmin Only
 *        [ Tanggal Mulai (DatePicker) ] [ Tanggal Selesai (DatePicker) ] [ Status Promo (Select) ]
 * ===================================================================================
 * ROW 1: KasBesarStatsOverview (Full Width - 4 Stat Cards)
 *        [ 1. Total Kas Besar (Rp & Fisik) ] [ 2. Arus Barang Masuk (Rp) ]
 *        [ 3. Kas Bersih Keluar (Rp) ]       [ 4. Beban Subsidi Promo (Rp & %) ]
 * ===================================================================================
 * ROW 2: StockCashflowTrendChart (Full Width - Line Chart Tren Deret Waktu)
 *        Inbound Restock Value vs Gross Sales Potential vs Net Realized Cash
 * ===================================================================================
 * ROW 3: PromoImpactTableWidget (Full Width - Ranking Table 10 Produk)
 *        SKU | Nama Produk | Varian | Qty Terjual | Nilai Gross | Subsidi Promo | Kas Bersih
 * ===================================================================================
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        if (! Filament::auth()->user()?->isSuperAdmin()) {
            return $schema->components([]);
        }

        return $schema->components([
            Section::make()
                ->schema([
                    DatePicker::make('startDate')
                        ->label('Tanggal Mulai')
                        ->default(now()->startOfMonth()),

                    DatePicker::make('endDate')
                        ->label('Tanggal Selesai')
                        ->default(now()->endOfDay()),

                    Select::make('promoStatus')
                        ->label('Status Promo')
                        ->options([
                            'all' => 'Semua Transaksi',
                            'promo_only' => 'Hanya Transaksi Promo/Diskon',
                            'non_promo' => 'Hanya Transaksi Normal',
                        ])
                        ->default('all'),
                ])
                ->columns(3),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            KasBesarStatsOverview::class,
            StockCashflowTrendChart::class,
            PromoImpactTableWidget::class,
        ];
    }
}
