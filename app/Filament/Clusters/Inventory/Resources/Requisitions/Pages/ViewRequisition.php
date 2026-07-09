<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;

class ViewRequisition extends ViewRecord
{
    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
