<?php

namespace Modules\Inventory\Classes\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Inventory\Models\Requisition;

class RequisitionVoucherPdfService
{
    public function render(Requisition $requisition): string
    {
        $requisition->loadMissing([
            'department',
            'branch',
            'items.inventoryItem',
            'requestor',
            'approvedBy',
            'issuedBy',
        ]);

        return Pdf::loadView('inventory::pdf.requisition-voucher', [
            'requisition' => $requisition,
        ])->setPaper('a4')->output();
    }

    public function filename(Requisition $requisition): string
    {
        return sprintf('requisition-%s.pdf', $requisition->requisition_number);
    }
}
