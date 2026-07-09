<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\SupplierResource;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;
}
