<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\StoreResource;

class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
