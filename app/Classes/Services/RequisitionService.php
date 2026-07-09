<?php

namespace Modules\Inventory\Classes\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\Department;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionItem;

class RequisitionService
{
    public function __construct(
        protected DocumentNumberingService $numbering,
        protected IssueToWardService $issueToWard,
        protected IssueToPharmacyService $issueToPharmacy,
    ) {}

    public function create(array $data): Requisition
    {
        $branchId = $data['branch_id'];

        Department::byBranch($branchId)
            ->where('id', $data['department_id'])
            ->firstOrFail();

        $requisition = Requisition::create([
            'requisition_number' => $this->numbering->generate('REQ', $branchId),
            'requestor_id' => $data['requestor_id'] ?? Auth::id(),
            'department_id' => $data['department_id'],
            'branch_id' => $branchId,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $item) {
            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'inventory_item_id' => $item['inventory_item_id'],
                'quantity_requested' => $item['quantity_requested'],
            ]);
        }

        return $requisition->load('items');
    }

    public function approve(Requisition $requisition, ?array $approvedQuantities = null): void
    {
        $requisition->update([
            'status' => RequisitionStatus::Approved,
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        foreach ($requisition->items as $item) {
            $approved = $approvedQuantities[$item->id] ?? $item->quantity_requested;
            $item->update(['quantity_approved' => $approved]);
        }
    }

    public function decline(Requisition $requisition, string $reason): void
    {
        $requisition->update([
            'status' => RequisitionStatus::Declined,
            'declined_by' => Auth::id(),
            'declined_at' => now(),
            'decline_reason' => $reason,
        ]);
    }

    public function cancel(Requisition $requisition): void
    {
        if (! $requisition->status->isTerminal()) {
            $requisition->update([
                'status' => RequisitionStatus::Cancelled,
            ]);
        }
    }

    public function issue(RequisitionItem $item, int $qty): void
    {
        $requisition = $item->requisition;

        $remaining = ($item->quantity_approved ?? $item->quantity_requested) - $item->quantity_issued;

        if ($qty > $remaining) {
            throw new \RuntimeException(
                "Cannot issue {$qty}, only {$remaining} remaining for item {$item->id}."
            );
        }

        $inventoryItem = $item->inventoryItem;

        if ($inventoryItem->medication_id) {
            $this->issueToPharmacy->issue($item, $qty);
        } else {
            $this->issueToWard->issue($item, $qty);
        }

        $allIssued = $requisition->items->every(
            fn (RequisitionItem $i) => $i->quantity_issued >= ($i->quantity_approved ?? $i->quantity_requested)
        );

        if ($allIssued) {
            $requisition->update([
                'status' => RequisitionStatus::Issued,
                'issued_by' => Auth::id(),
                'issued_at' => now(),
            ]);
        } else {
            $requisition->update([
                'status' => RequisitionStatus::PartiallyIssued,
            ]);
        }
    }

    public function close(Requisition $requisition, string $reason): void
    {
        $requisition->update([
            'status' => RequisitionStatus::Closed,
            'closed_reason' => $reason,
            'closed_at' => now(),
        ]);
    }

    public function fetchForRequestor(User $user)
    {
        return Requisition::where('requestor_id', $user->id)
            ->with('items.inventoryItem')
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
