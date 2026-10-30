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
 * ROW 0: Header Filters Form - Superadmin Only
 *        [ Tanggal Mulai - DatePicker ] [ Tanggal Selesai - DatePicker ] [ Status Promo - Select ]
 * ===================================================================================
 * ROW 1: KasBesarStatsOverview - Full Width 4 Stat Cards
 *        [ 1. Nilai Stok Gudang ]       [ 2. Nilai Belanja Barang ]
 *        [ 3. Penerimaan Kas Bersih ]   [ 4. Potongan Diskon dan Promo ]
 * ===================================================================================
 * ROW 2: StockCashflowTrendChart - Full Width Line Chart Tren Deret Waktu
 *        Belanja Barang Masuk vs Omzet Penjualan Kotor vs Penerimaan Kas Bersih
 * ===================================================================================
 * ROW 3: PromoImpactTableWidget - Full Width Ranking Table 10 Produk
 *        Kode SKU | Nama Produk | Varian | Jumlah Terjual | Omzet Kotor | Potongan Promo | Kas Bersih Diterima
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
                            'promo_only' => 'Hanya Transaksi dengan Promo',
                            'non_promo' => 'Hanya Transaksi Normal tanpa Promo',
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
