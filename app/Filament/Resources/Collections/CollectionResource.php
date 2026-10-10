<?php

namespace App\Filament\Resources\Collections;

use App\Enums\AdminFeature;
use App\Filament\Concerns\HasAdminFeatureAccess;
use App\Filament\Resources\Collections\Pages\CreateCollection;
use App\Filament\Resources\Collections\Pages\EditCollection;
use App\Filament\Resources\Collections\Pages\ListCollections;
use App\Filament\Resources\Collections\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Collections\Schemas\CollectionForm;
use App\Filament\Resources\Collections\Tables\CollectionsTable;
use App\Models\Collection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CollectionResource extends Resource
{
    use HasAdminFeatureAccess;

    protected static ?string $model = Collection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Koleksi (Series)';

    protected static ?string $modelLabel = 'Koleksi';

    protected static ?string $pluralModelLabel = 'Koleksi Produk';

    protected static ?int $navigationSort = 4;

    public static function getAdminFeature(): AdminFeature
    {
        return AdminFeature::Collections;
    }

    public static function form(Schema $schema): Schema
    {
        return CollectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CollectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollections::route('/'),
            'create' => CreateCollection::route('/create'),
            'edit' => EditCollection::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
