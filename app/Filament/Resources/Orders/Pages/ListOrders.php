<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua')
                ->badge(fn (): int => Order::query()->count()),

            'pending' => Tab::make('Menunggu Pembayaran')
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_PENDING)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Order::STATUS_PENDING)),

            'processing' => Tab::make('Perlu Diproses')
                ->badge(fn (): int => Order::query()->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROCESSING])->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROCESSING])),

            'shipped' => Tab::make('Sedang Dikirim')
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_SHIPPED)->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Order::STATUS_SHIPPED)),

            'completed' => Tab::make('Selesai')
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_COMPLETED)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Order::STATUS_COMPLETED)),

            'cancelled_expired' => Tab::make('Dibatalkan / Expired')
                ->badge(fn (): int => Order::query()->whereIn('status', [Order::STATUS_CANCELLED, Order::STATUS_EXPIRED])->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [Order::STATUS_CANCELLED, Order::STATUS_EXPIRED])),
        ];
    }
}
