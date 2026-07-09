<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Core\Enums\NavigationGroup;
use Modules\Inventory\Filament\Clusters\Inventory\InventoryCluster;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages\CreateRequisition;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages\EditRequisition;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages\ListRequisitions;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages\ViewRequisition;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Schemas\RequisitionForm;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Schemas\RequisitionInfolist;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Tables\RequisitionsTable;
use Modules\Inventory\Models\Requisition;

class RequisitionResource extends Resource
{
    protected static ?string $model = Requisition::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::CLINICAL;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'requisition_number';

    public static function form(Schema $schema): Schema
    {
        return RequisitionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RequisitionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RequisitionsTable::configure($table);
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
            'index' => ListRequisitions::route('/'),
            'create' => CreateRequisition::route('/create'),
            'view' => ViewRequisition::route('/{record}'),
            'edit' => EditRequisition::route('/{record}/edit'),
        ];
    }
}
