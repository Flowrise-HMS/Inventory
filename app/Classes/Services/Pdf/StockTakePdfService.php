<?php

namespace Modules\Inventory\Classes\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Inventory\Classes\Services\StockTakeService;
use Modules\Inventory\Enums\StockTakeStatus;
use Modules\Inventory\Models\StockTake;

class StockTakePdfService
{
    public function countSheet(StockTake $stockTake): string
    {
        $this->load($stockTake);

        return Pdf::loadView('inventory::pdf.stock-take-count-sheet', [
            'stockTake' => $stockTake,
            // The paper sheet goes to counters: keep a blind count blind.
            'showSystem' => ! ($stockTake->is_blind && $stockTake->status === StockTakeStatus::InProgress),
        ])->setPaper('a4')->output();
    }

    public function varianceReport(StockTake $stockTake): string
    {
        $this->load($stockTake);

        return Pdf::loadView('inventory::pdf.stock-take-variance-report', [
            'stockTake' => $stockTake,
            'summary' => app(StockTakeService::class)->summary($stockTake),
            'lines' => $stockTake->lines->filter(fn ($line): bool => $line->variance() !== null && $line->variance() !== 0)->values(),
        ])->setPaper('a4')->output();
    }

    public function filename(StockTake $stockTake, string $kind): string
    {
        return sprintf('%s-%s.pdf', $kind, $stockTake->reference);
    }

    protected function load(StockTake $stockTake): void
    {
        $stockTake->loadMissing(['store', 'branch', 'startedBy', 'submittedBy', 'approvedBy']);
        $stockTake->setRelation('lines', $stockTake->lines()
            ->with(['inventoryItem.unit', 'adjustmentTransaction'])
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_take_lines.inventory_item_id')
            ->select('stock_take_lines.*')
            ->orderBy('inventory_items.name')
            ->orderBy('stock_take_lines.expiry_date')
            ->get());
    }
}
