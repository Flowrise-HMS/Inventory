<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTakes\StockTakeResource;

class ListStockTakes extends ListRecords
{
    protected static string $resource = StockTakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Start stock-take')),
        ];
    }
}
