<?php

namespace Modules\Inventory\Classes\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Inventory\Models\InventoryTransaction;

class AdjustmentVoucherPdfService
{
    public function render(InventoryTransaction $transaction): string
    {
        $transaction->loadMissing([
            'inventoryItem.unit',
            'branch',
        ]);

        return Pdf::loadView('inventory::pdf.adjustment-voucher', [
            'transaction' => $transaction,
        ])->setPaper('a4')->output();
    }

    public function filename(InventoryTransaction $transaction): string
    {
        return sprintf('adjustment-%s.pdf', $transaction->id);
    }
}
