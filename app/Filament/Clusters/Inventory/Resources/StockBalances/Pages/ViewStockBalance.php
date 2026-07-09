<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Pages;

use Filament\Resources\Pages\ViewRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\StockBalanceResource;

class ViewStockBalance extends ViewRecord
{
    protected static string $resource = StockBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
