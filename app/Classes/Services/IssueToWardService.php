<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Classes\Support\Feature;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\RequisitionItem;

class IssueToWardService
{
    public function __construct(
        protected StockLedgerService $stockLedger,
    ) {}

    public function issue(RequisitionItem $requisitionItem, int $qty): void
    {
        if (! Feature::wardRequisitionsEnabled()) {
            throw new \RuntimeException('Inventory ward requisitions are disabled.');
        }

        $requisition = $requisitionItem->requisition;

        DB::transaction(function () use ($requisitionItem, $requisition, $qty): void {
            $this->stockLedger->transferQuantity(
                itemId: $requisitionItem->inventory_item_id,
                fromBranchId: $requisition->branch_id,
                fromLocation: StockLocationType::Dispensary,
                fromDepartmentId: null,
                fromStockTransferId: null,
                toBranchId: $requisition->branch_id,
                toLocation: StockLocationType::Ward,
                toDepartmentId: $requisition->department_id,
                toStockTransferId: null,
                qty: $qty,
                transactionType: TransactionType::Issue,
                reference: $requisitionItem,
            );

            $requisitionItem->increment('quantity_issued', $qty);
        });
    }
}
