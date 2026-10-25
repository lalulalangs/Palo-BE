<?php

namespace App\Filament\Resources\Orders;

use App\Enums\AdminFeature;
use App\Enums\OrderStatus;
use App\Filament\Concerns\HasAdminFeatureAccess;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OrderResource extends Resource
{
    use HasAdminFeatureAccess;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Penjualan';

    protected static ?string $navigationLabel = 'Pesanan';

    protected static ?string $modelLabel = 'Pesanan';

    protected static ?string $pluralModelLabel = 'Daftar Pesanan';

    protected static ?string $recordTitleAttribute = 'order_number';

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'order_number',
            'guest_reference_code',
            'cod_reference_code',
            'shipping_recipient_name',
            'shipping_phone',
        ];
    }

    /**
     * @param  Order  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Order $record */
        $details = [
            'Penerima' => (string) $record->shipping_recipient_name,
            'Status' => OrderStatus::labelFor($record->status),
        ];

        if ($record->isCod() && $record->cod_reference_code) {
            $details['Ref COD'] = $record->cod_reference_code;
        } elseif ($record->isWhatsAppOrder() && $record->guest_reference_code) {
            $details['Ref WA'] = $record->guest_reference_code;
        }

        return $details;
    }

    protected static ?int $navigationSort = 1;

    public static function getAdminFeature(): AdminFeature
    {
        return AdminFeature::Orders;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /**
     * Eager load relasi yang dipakai halaman detail (infolist) agar tidak
     * terjadi N+1 saat membuka pesanan dengan banyak item/riwayat.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->with([
                'user',
                'payment',
                'statusHistories.changedBy',
                'items.sku.product.thumbnail',
                // Infolist menampilkan siapa yang mengonfirmasi COD dan
                // menyetujui refund; tanpa ini jadi 2 query lazy per order.
                'confirmedBy',
                'refundApprovedBy',
            ]);
    }
}
