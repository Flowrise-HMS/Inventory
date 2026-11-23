<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\StoreResource;

class ViewStore extends ViewRecord
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
