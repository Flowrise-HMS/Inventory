<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages\CreateStore;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages\EditStore;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages\ListStores;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages\ViewStore;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Schemas\StoreForm;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Schemas\StoreInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Tables\StoresTable;
use Modules\Inventory\Models\Store;

class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return StoreForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StoreInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoresTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'view' => ViewStore::route('/{record}'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }
}
