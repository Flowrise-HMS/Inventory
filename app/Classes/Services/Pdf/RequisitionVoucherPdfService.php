<?php

namespace Modules\Inventory\Classes\Services\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Inventory\Classes\Services\RequisitionIssueService;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionIssue;

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
            'store',
            'location',
            'issues.inventoryItem',
            'issues.issuedBy',
            'issues.collectedBy',
            'issues.receiptConfirmedBy',
        ]);

        $issueService = app(RequisitionIssueService::class);

        // Lines handed over together share one signature: print it once per hand-over.
        $handovers = $requisition->issues
            ->sortBy('issued_at')
            ->groupBy('handover_reference')
            ->map(fn ($issues) => [
                'issues' => $issues->values(),
                'first' => $issues->first(),
                'signature' => $issueService->signatureDataUri($issues->first(fn (RequisitionIssue $i) => $i->hasSignature()) ?? $issues->first()),
            ])
            ->values();

        $receivedByItem = $requisition->issues
            ->whereNotNull('quantity_received')
            ->groupBy('requisition_item_id')
            ->map(fn ($issues) => (int) $issues->sum('quantity_received'));

        return Pdf::loadView('inventory::pdf.requisition-voucher', [
            'requisition' => $requisition,
            'handovers' => $handovers,
            'receivedByItem' => $receivedByItem,
        ])->setPaper('a4')->output();
    }

    public function filename(Requisition $requisition): string
    {
        return sprintf('requisition-%s.pdf', $requisition->requisition_number);
    }
}
