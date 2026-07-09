<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;

class CreateRequisition extends CreateRecord
{
    protected static string $resource = RequisitionResource::class;
}
