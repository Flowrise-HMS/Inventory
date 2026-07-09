<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use Modules\Inventory\Models\PurchaseOrder;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'po_number';

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
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
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
