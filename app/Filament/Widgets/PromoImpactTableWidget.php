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
            ->heading('Ranking Produk Beban Subsidi Promo Terbesar')
            ->description('10 produk dengan alokasi subsidi promo tertinggi pada periode terpilih')
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
                    ->label('SKU')
                    ->searchable(false)
                    ->sortable(false),

                TextColumn::make('product_name')
                    ->label('Nama Produk')
                    ->searchable(false)
                    ->sortable(false),

                TextColumn::make('variant_name')
                    ->label('Varian')
                    ->placeholder('-'),

                TextColumn::make('units_sold')
                    ->label('Qty Terjual')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('gross_sales_value')
                    ->label('Nilai Normal (Gross)')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.'))
                    ->alignEnd(),

                TextColumn::make('total_promo_subsidy')
                    ->label('Subsidi Promo (Rp)')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.'))
                    ->color('danger')
                    ->alignEnd(),

                TextColumn::make('net_cash_realized')
                    ->label('Kas Bersih Diterima (Net)')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.'))
                    ->color('success')
                    ->alignEnd(),
            ]);
    }
}
