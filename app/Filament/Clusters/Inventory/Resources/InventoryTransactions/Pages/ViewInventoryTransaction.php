<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Pages;

use Filament\Resources\Pages\ViewRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\InventoryTransactionResource;

class ViewInventoryTransaction extends ViewRecord
{
    protected static string $resource = InventoryTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
