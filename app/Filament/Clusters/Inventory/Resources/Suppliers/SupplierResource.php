<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages\CreateSupplier;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages\EditSupplier;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages\ListSuppliers;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages\ViewSupplier;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Schemas\SupplierForm;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Schemas\SupplierInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Tables\SuppliersTable;
use Modules\Inventory\Models\Supplier;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplierInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
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
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'view' => ViewSupplier::route('/{record}'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
