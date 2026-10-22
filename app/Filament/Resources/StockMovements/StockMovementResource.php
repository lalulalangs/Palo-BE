<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\AdminFeature;
use App\Filament\Concerns\HasAdminFeatureAccess;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class StockMovementResource extends Resource
{
    use HasAdminFeatureAccess;

    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';

    protected static ?string $navigationLabel = 'Riwayat Mutasi Stok';

    protected static ?int $navigationSort = 1;

    public static function getAdminFeature(): AdminFeature
    {
        return AdminFeature::StockMovements;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
