<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages\CreateStockTransfer;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages\EditStockTransfer;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages\ListStockTransfers;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages\ViewStockTransfer;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Schemas\StockTransferForm;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Schemas\StockTransferInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Tables\StockTransfersTable;
use Modules\Inventory\Models\StockTransfer;

class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'transfer_number';

    public static function form(Schema $schema): Schema
    {
        return StockTransferForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockTransferInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockTransfersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'view' => ViewStockTransfer::route('/{record}'),
            'edit' => EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
