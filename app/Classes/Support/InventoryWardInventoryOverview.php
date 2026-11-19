<?php

namespace Modules\Inventory\Classes\Support;

use Modules\Core\Contracts\WardInventoryOverviewContract;
use Modules\Core\Support\ModuleAvailability;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\WardItemIssueSeverity;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\WardItemIssue;

class InventoryWardInventoryOverview implements WardInventoryOverviewContract
{
    public function summaryForWard(string $wardId, string $branchId): array
    {
        if (! ModuleAvailability::inventoryEnabled()) {
            return [
                'pending_requisitions' => 0,
                'awaiting_endorsement' => 0,
                'low_stock_count' => 0,
                'open_issues_count' => 0,
                'critical_issues_count' => 0,
            ];
        }

        $pendingRequisitions = Requisition::query()
            ->where('branch_id', $branchId)
            ->where('location_id', $wardId)
            ->whereIn('status', [
                RequisitionStatus::PendingIncharge->value,
                RequisitionStatus::Pending->value,
                RequisitionStatus::Approved->value,
                RequisitionStatus::PartiallyIssued->value,
            ])
            ->count();

        $awaitingEndorsement = Requisition::query()
            ->where('branch_id', $branchId)
            ->where('location_id', $wardId)
            ->where('status', RequisitionStatus::PendingIncharge->value)
            ->count();

        $lowStockCount = StockBalance::query()
            ->where('branch_id', $branchId)
            ->where('location_type', StockLocationType::Ward->value)
            ->where('location_id', $wardId)
            ->whereNotNull('reorder_point')
            ->whereColumn('quantity_on_hand', '<=', 'reorder_point')
            ->count();

        $openIssuesQuery = WardItemIssue::query()
            ->where('branch_id', $branchId)
            ->where('location_id', $wardId)
            ->open();

        return [
            'pending_requisitions' => $pendingRequisitions,
            'awaiting_endorsement' => $awaitingEndorsement,
            'low_stock_count' => $lowStockCount,
            'open_issues_count' => (clone $openIssuesQuery)->count(),
            'critical_issues_count' => (clone $openIssuesQuery)
                ->whereIn('severity', [WardItemIssueSeverity::HIGH->value, WardItemIssueSeverity::CRITICAL->value])
                ->count(),
        ];
    }
}
