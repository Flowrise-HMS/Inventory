<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Pages\ListStockBalances;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Pages\ViewStockBalance;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Schemas\StockBalanceInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Tables\StockBalancesTable;
use Modules\Inventory\Models\StockBalance;

class StockBalanceResource extends Resource
{
    protected static ?string $model = StockBalance::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return StockBalanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockBalancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockBalances::route('/'),
            'view' => ViewStockBalance::route('/{record}'),
        ];
    }
}
