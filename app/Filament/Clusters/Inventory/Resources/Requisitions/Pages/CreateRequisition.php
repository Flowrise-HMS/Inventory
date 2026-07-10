<?php

namespace Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Classes\Services\DocumentNumberingService;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;

class CreateRequisition extends CreateRecord
{
    protected static string $resource = RequisitionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['requisition_number'] = app(DocumentNumberingService::class)->generate('REQ', $data['branch_id']);
        $data['requestor_id'] ??= Auth::id();
        // Set explicitly (rather than relying on the DB column default) so the
        // in-memory record's status is hydrated for the items repeater's visible() check.
        $data['status'] = RequisitionStatus::Pending->value;

        return $data;
    }
}
