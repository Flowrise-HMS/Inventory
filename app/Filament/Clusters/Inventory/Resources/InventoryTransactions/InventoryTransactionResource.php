<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Pages\ListInventoryTransactions;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Pages\ViewInventoryTransaction;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Schemas\InventoryTransactionInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Tables\InventoryTransactionsTable;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryTransactionResource extends Resource
{
    protected static ?string $model = InventoryTransaction::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return InventoryTransactionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryTransactionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryTransactions::route('/'),
            'view' => ViewInventoryTransaction::route('/{record}'),
        ];
    }
}
