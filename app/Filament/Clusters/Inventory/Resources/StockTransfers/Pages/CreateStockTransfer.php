<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\StockTransferResource;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;
}
