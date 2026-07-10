<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Inventory\Classes\Services\DocumentNumberingService;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\PurchaseOrderResource;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['po_number'] = app(DocumentNumberingService::class)->generate('PO', $data['branch_id']);
        // Set explicitly (rather than relying on the DB column default) so the
        // in-memory record's status is hydrated for the items repeater's visible() check.
        $data['status'] = PurchaseOrderStatus::Draft->value;

        return $data;
    }
}
