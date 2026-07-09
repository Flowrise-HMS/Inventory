<?php

namespace Modules\Inventory\Classes\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Inventory\Models\StockTransfer;

class TransferNotePdfService
{
    public function render(StockTransfer $transfer): string
    {
        $transfer->loadMissing([
            'fromBranch',
            'toBranch',
            'items.inventoryItem',
            'shippedBy',
            'receivedBy',
        ]);

        return Pdf::loadView('inventory::pdf.transfer-note', [
            'transfer' => $transfer,
        ])->setPaper('a4')->output();
    }

    public function filename(StockTransfer $transfer): string
    {
        return sprintf('transfer-%s.pdf', $transfer->transfer_number);
    }
}
