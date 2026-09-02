<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Core\Filament\Support\SuperAdminExportAction;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\StockBalanceResource;
use Modules\Inventory\Filament\Exports\StockBalanceExporter;

class ListStockBalances extends ListRecords
{
    protected static string $resource = StockBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SuperAdminExportAction::make(StockBalanceExporter::class),
        ];
    }
}
