<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\InventoryItemResource;

class ViewInventoryItem extends ViewRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
