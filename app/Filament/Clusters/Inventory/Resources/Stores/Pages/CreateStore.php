<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\StoreResource;

class CreateStore extends CreateRecord
{
    protected static string $resource = StoreResource::class;
}
