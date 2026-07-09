<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\InventoryTransactionResource;

class ListInventoryTransactions extends ListRecords
{
    protected static string $resource = InventoryTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
