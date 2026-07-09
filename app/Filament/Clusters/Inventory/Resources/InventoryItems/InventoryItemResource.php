<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages\CreateInventoryItem;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages\EditInventoryItem;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages\ListInventoryItems;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages\ViewInventoryItem;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Schemas\InventoryItemForm;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Schemas\InventoryItemInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Tables\InventoryItemsTable;
use Modules\Inventory\Models\InventoryItem;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return InventoryItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InventoryItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryItemsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryItems::route('/'),
            'create' => CreateInventoryItem::route('/create'),
            'view' => ViewInventoryItem::route('/{record}'),
            'edit' => EditInventoryItem::route('/{record}/edit'),
        ];
    }
}
