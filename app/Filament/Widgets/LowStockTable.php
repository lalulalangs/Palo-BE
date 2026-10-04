<?php

namespace App\Filament\Widgets;

use App\Domain\Catalog\Models\Sku;
use App\Domain\Shared\Models\AppSetting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * SKU yang perlu restok.
 *
 * ===================================================================
 *  MENGAPA ANGKANYA BUKAN SEKADAR `on_hand`
 * ===================================================================
 * Yang ditampilkan adalah "Tersedia" = `on_hand - SUM(reservasi aktif)`,
 * sama persis dengan yang dilihat buyer (ARSITEKTUR.md §5, PRD §3.6).
 *
 * Kalau widget ini hanya menampilkan `on_hand`, admin akan melihat "stok 10"
 * padahal 10 itu sudah dipegang 8 order yang belum dibayar, lalu memutuskan
 * tidak perlu restok. Salah baca stok di dashboard berujung oversell.
 *
 * Ambang batas dibaca dari `app_settings` (`inventory.low_stock_threshold`)
 * supaya bisa diubah tanpa deploy.
 */
class LowStockTable extends TableWidget
{
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 3;

    protected static ?string $heading = 'SKU Perlu Restok';

    public static function canView(): bool
    {
        return Gate::check('viewAny', Sku::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->columns([
                TextColumn::make('code')
                    ->label('Kode SKU')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product_name')
                    ->label('Produk')
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('on_hand')
                    ->label('Stok Fisik')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('reserved_quantity')
                    ->label('Direservasi')
                    ->numeric()
                    ->color('warning')
                    ->description('Sudah dipegang order yang belum dibayar.'),

                TextColumn::make('available_quantity')
                    ->label('Tersedia')
                    ->numeric()
                    ->color(fn (int $state): string => match (true) {
                        $state <= 0 => 'danger',
                        $state <= 3 => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('available_quantity', 'asc')
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Stok aman')
            ->emptyStateDescription('Tidak ada SKU aktif yang berada di atau bawah ambang batas.');
    }

    /**
     * Query dengan `reserved` dan `available` dihitung di SQL.
     *
     * Kenapa tidak memuat semua SKU lalu memanggil `availableQuantity()`:
     * method itu menembak satu query per SKU untuk reservasi, jadi 500 SKU
     * jadi 501 query setiap kali dashboard dibuka. Subquery yang sudah
     * di-group menghasilkan satu baris per SKU dalam satu query.
     *
     * Hanya reservasi `active` yang belum kedaluwarsa yang dihitung. Yang
     * sudah `consumed` sudah menjadi pengurangan `on_hand`, dan yang
     * `released` sudah dilepas. Kalau ikut dihitung, stok terpotong dua kali
     * (prinsip yang sama dengan `Sku::reservedQuantity()`).
     *
     * @return EloquentBuilder<Sku>
     */
    private function buildQuery(): EloquentBuilder
    {
        $threshold = (int) AppSetting::get('inventory.low_stock_threshold', 5);

        // Ekspresi "tersedia" yang sama dipakai di SELECT dan di WHERE. Kalau
        // keduanya berbeda, tabel bisa menampilkan SKU yang tidak lolos filter.
        $available = 'GREATEST(skus.on_hand - COALESCE(sr.reserved, 0), 0)';

        $reservedSubquery = DB::table('stock_reservations')
            ->select('sku_id', DB::raw('COALESCE(SUM(quantity), 0) AS reserved'))
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->groupBy('sku_id');

        return Sku::query()
            ->select('skus.*', 'products.name AS product_name')
            ->addSelect(DB::raw('COALESCE(sr.reserved, 0) AS reserved_quantity'))
            ->addSelect(DB::raw($available.' AS available_quantity'))
            ->leftJoinSub($reservedSubquery, 'sr', 'sr.sku_id', '=', 'skus.id')
            ->leftJoin('products', 'products.id', '=', 'skus.product_id')
            ->where('skus.is_active', true)
            ->whereRaw($available.' <= ?', [$threshold])
            ->orderBy('available_quantity');
    }
}
