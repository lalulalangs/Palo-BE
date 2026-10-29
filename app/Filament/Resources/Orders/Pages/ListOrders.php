<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $counts = $this->orderStatusCounts();
        $countOf = fn (string ...$statuses): int => array_sum(
            array_map(fn (string $status): int => $counts[$status] ?? 0, $statuses)
        );

        return [
            'all' => Tab::make('Semua')
                ->badge(fn (): int => array_sum($counts)),

            'awaiting_confirmation' => Tab::make('Menunggu Konfirmasi CS')
                ->badge(fn (): int => $countOf(Order::STATUS_AWAITING_CONFIRMATION))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Order::STATUS_AWAITING_CONFIRMATION)),

            'pending' => Tab::make('Menunggu Pembayaran')
                ->badge(fn (): int => $countOf(Order::STATUS_PENDING))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Order::STATUS_PENDING)),

            'processing' => Tab::make('Perlu Diproses')
                ->badge(fn (): int => $countOf(Order::STATUS_PAID, Order::STATUS_PROCESSING))
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROCESSING])),

            'shipped' => Tab::make('Sedang Dikirim')
                ->badge(fn (): int => $countOf(Order::STATUS_SHIPPED))
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Order::STATUS_SHIPPED)),

            'completed' => Tab::make('Selesai')
                ->badge(fn (): int => $countOf(Order::STATUS_COMPLETED))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Order::STATUS_COMPLETED)),

            'cancelled_expired' => Tab::make('Dibatalkan / Expired')
                ->badge(fn (): int => $countOf(Order::STATUS_CANCELLED, Order::STATUS_EXPIRED))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [Order::STATUS_CANCELLED, Order::STATUS_EXPIRED])),
        ];
    }

    /**
     * Satu query agregat untuk seluruh badge tab.
     *
     * Sebelumnya setiap tab memanggil COUNT sendiri (7 query), dan closure-nya
     * dievaluasi ulang setiap ketikan pencarian atau ganti halaman. Cache 60
     * detik: badge boleh sedikit tertinggal, yang penting tidak dihitung ulang
     * terus-menerus.
     *
     * @return array<string, int>
     */
    private function orderStatusCounts(): array
    {
        return Cache::remember('orders:status-counts', 60, function (): array {
            /** @var array<string, int> $counts */
            $counts = Order::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn (mixed $total): int => (int) $total)
                ->all();

            return $counts;
        });
    }
}
