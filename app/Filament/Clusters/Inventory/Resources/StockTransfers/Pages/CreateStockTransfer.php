<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Inventory\Classes\Services\DocumentNumberingService;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\StockTransferResource;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['transfer_number'] = app(DocumentNumberingService::class)->generate('TRF', $data['from_branch_id']);
        // Set explicitly (rather than relying on the DB column default) so the
        // in-memory record's status is hydrated for the items repeater's visible() check.
        $data['status'] = StockTransferStatus::Draft->value;

        return $data;
    }
}
