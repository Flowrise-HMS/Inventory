<?php

namespace Modules\Inventory\Classes\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Inventory\Models\PurchaseOrder;

class GoodsReceivedNotePdfService
{
    public function render(PurchaseOrder $purchaseOrder): string
    {
        $purchaseOrder->loadMissing([
            'supplier',
            'branch',
            'items.inventoryItem',
            'receipts.items.purchaseOrderItem.inventoryItem',
            'receipts.receivedByUser',
        ]);

        return Pdf::loadView('inventory::pdf.grn', [
            'purchaseOrder' => $purchaseOrder,
        ])->setPaper('a4')->output();
    }

    public function filename(PurchaseOrder $purchaseOrder): string
    {
        return sprintf('grn-%s.pdf', $purchaseOrder->po_number);
    }
}
