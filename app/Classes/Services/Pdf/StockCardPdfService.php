<?php

namespace Modules\Inventory\Classes\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Modules\Inventory\Models\InventoryItem;

class StockCardPdfService
{
    public function render(
        InventoryItem $item,
        ?string $branchId = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): string {
        $item->loadMissing(['unit', 'medication']);

        $query = $item->transactions()->with(['branch', 'reference']);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $transactions = $query->orderBy('created_at')->get();

        return Pdf::loadView('inventory::pdf.stock-card', [
            'item' => $item,
            'transactions' => $transactions,
            'branchId' => $branchId,
            'from' => $from,
            'to' => $to,
        ])->setPaper('a4')->output();
    }

    public function filename(InventoryItem $item): string
    {
        return sprintf('stock-card-%s.pdf', $item->sku ?? $item->id);
    }
}
