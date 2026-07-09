<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\InventoryItemResource;

class CreateInventoryItem extends CreateRecord
{
    protected static string $resource = InventoryItemResource::class;
}
