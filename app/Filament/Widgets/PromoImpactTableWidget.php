<?php

namespace App\Filament\Widgets;

use App\Services\Inventory\InventoryCashflowService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class PromoImpactTableWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) Filament::auth()->user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('10 Produk dengan Beban Promo Tertinggi')
            ->description('Daftar produk dengan akumulasi potongan diskon terbesar selama periode terpilih.')
            ->emptyStateHeading('Tidak Ada Beban Promo')
            ->emptyStateDescription('Tidak ada transaksi dengan potongan promo yang tercatat pada rentang waktu ini.')
            ->records(function () {
                $start = filled($this->pageFilters['startDate'] ?? null)
                    ? Carbon::parse($this->pageFilters['startDate'])
                    : now()->startOfMonth();

                $end = filled($this->pageFilters['endDate'] ?? null)
                    ? Carbon::parse($this->pageFilters['endDate'])
                    : now()->endOfDay();

                return app(InventoryCashflowService::class)
                    ->getTopPromoImpactProducts($start, $end, 10)
                    ->map(function ($row) {
                        $arr = (array) $row;
                        $arr['key'] = (string) ($row->sku_code ?? uniqid());
                        return $arr;
                    });
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('sku_code')
                    ->label('Kode SKU')
                    ->fontFamily('mono')
                    ->color('gray'),

                TextColumn::make('product_name')
                    ->label('Nama Produk')
                    ->weight('semibold'),

                TextColumn::make('variant_name')
                    ->label('Varian')
                    ->placeholder('-')
                    ->color('gray'),

                TextColumn::make('units_sold')
                    ->label('Jumlah Terjual')
                    ->numeric()
                    ->extraCellAttributes(['class' => 'tabular-nums'])
                    ->alignEnd()
                    ->suffix(' unit'),

                TextColumn::make('gross_sales_value')
                    ->label('Omzet Kotor')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.'))
                    ->extraCellAttributes(['class' => 'tabular-nums'])
                    ->alignEnd(),

                TextColumn::make('total_promo_subsidy')
                    ->label('Potongan Promo')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.'))
                    ->color('warning')
                    ->weight('medium')
                    ->extraCellAttributes(['class' => 'tabular-nums'])
                    ->alignEnd(),

                TextColumn::make('net_cash_realized')
                    ->label('Kas Bersih Diterima')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.'))
                    ->color('success')
                    ->weight('bold')
                    ->extraCellAttributes(['class' => 'tabular-nums'])
                    ->alignEnd(),
            ]);
    }
}
