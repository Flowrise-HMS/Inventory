<?php

namespace Modules\Inventory\Classes\Services;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Classes\Services\WardAccessService;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Support\Feature;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Events\RequisitionCreated;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\RequisitionItem;

class RequisitionService
{
    public function __construct(
        protected DocumentNumberingService $numbering,
        protected IssueToWardService $issueToWard,
        protected IssueToPharmacyService $issueToPharmacy,
        protected WardAccessService $wardAccess,
    ) {}

    public function create(array $data): Requisition
    {
        if (! Feature::wardRequisitionsEnabled() && ! Feature::pharmacyProcurementEnabled()) {
            throw new \RuntimeException('Inventory requisitions are disabled.');
        }

        $branchId = $data['branch_id'];

        Department::byBranch($branchId)
            ->where('id', $data['department_id'])
            ->firstOrFail();

        $locationId = $data['location_id'] ?? null;
        $ward = filled($locationId)
            ? Location::withoutGlobalScope('branch')->where('branch_id', $branchId)->find($locationId)
            : null;

        $requestorId = $data['requestor_id'] ?? Auth::id();
        $requestor = $requestorId ? User::find($requestorId) : null;

        $requiresEndorsement = $ward !== null
            && (bool) config('inventory.require_ward_incharge_endorsement', false)
            && ($requestor === null || ! $this->wardAccess->isWardIncharge($requestor, $ward));

        $initialStatus = $requiresEndorsement
            ? RequisitionStatus::PendingIncharge->value
            : RequisitionStatus::Pending->value;

        $requisition = Requisition::create([
            'requisition_number' => $this->numbering->generate('REQ', $branchId),
            'requestor_id' => $requestorId,
            'department_id' => $data['department_id'],
            'location_id' => $ward?->id,
            'branch_id' => $branchId,
            'status' => $initialStatus,
            'endorsed_by' => (! $requiresEndorsement && $ward !== null) ? $requestorId : null,
            'endorsed_at' => (! $requiresEndorsement && $ward !== null) ? now() : null,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $item) {
            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'inventory_item_id' => $item['inventory_item_id'],
                'quantity_requested' => $item['quantity_requested'],
            ]);
        }

        $requisition = $requisition->load('items');

        if ($requiresEndorsement && $ward !== null) {
            $incharges = $this->wardAccess->inchargeUsersForWard($ward);
            if ($incharges->isNotEmpty()) {
                Notification::make()
                    ->title(__('Ward requisition awaiting your endorsement'))
                    ->body(sprintf('%s — %s', $requisition->requisition_number, $ward->name))
                    ->info()
                    ->sendToDatabase($incharges);
            }
        } else {
            RequisitionCreated::dispatch($requisition);
        }

        return $requisition;
    }

    public function endorse(Requisition $requisition, ?User $endorser = null): void
    {
        if ($requisition->status !== RequisitionStatus::PendingIncharge) {
            throw new \RuntimeException('Only requisitions awaiting ward incharge endorsement can be endorsed.');
        }

        $endorser ??= Auth::user();

        $requisition->update([
            'status' => RequisitionStatus::Pending,
            'endorsed_by' => $endorser?->id,
            'endorsed_at' => now(),
        ]);

        RequisitionCreated::dispatch($requisition->fresh('items'));
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

        $requisition->load('items');

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
        if ($requisition->status !== RequisitionStatus::PartiallyIssued) {
            throw new \RuntimeException('Only partially issued requisitions can be closed.');
        }

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
