<?php

namespace App\Filament\Resources\StockOpnames;

use App\Enums\AdminFeature;
use App\Filament\Concerns\HasAdminFeatureAccess;
use App\Filament\Resources\StockOpnames\Pages\CreateStockOpname;
use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnames\Schemas\StockOpnameForm;
use App\Filament\Resources\StockOpnames\Tables\StockOpnamesTable;
use App\Models\StockOpname;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class StockOpnameResource extends Resource
{
    use HasAdminFeatureAccess;

    protected static ?string $model = StockOpname::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';

    protected static ?string $navigationLabel = 'Opname Stok';

    protected static ?int $navigationSort = 2;

    public static function getAdminFeature(): AdminFeature
    {
        return AdminFeature::StockOpnames;
    }

    public static function form(Schema $schema): Schema
    {
        return StockOpnameForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockOpnamesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOpnames::route('/'),
            'create' => CreateStockOpname::route('/create'),
            'edit' => EditStockOpname::route('/{record}/edit'),
        ];
    }
}
