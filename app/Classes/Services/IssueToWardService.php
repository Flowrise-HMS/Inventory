<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
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
        $requisition = $requisitionItem->requisition;

        DB::transaction(function () use ($requisitionItem, $requisition, $qty) {
            // Outbound from dispensary
            $this->stockLedger->lockAndDecrement(
                itemId: $requisitionItem->inventory_item_id,
                branchId: $requisition->branch_id,
                locationType: StockLocationType::Dispensary,
                departmentId: null,
                stockTransferId: null,
                qty: $qty,
                transactionType: TransactionType::Issue,
                reference: $requisitionItem,
            );

            // Inbound to ward
            $this->stockLedger->lockAndIncrement(
                itemId: $requisitionItem->inventory_item_id,
                branchId: $requisition->branch_id,
                locationType: StockLocationType::Ward,
                departmentId: $requisition->department_id,
                stockTransferId: null,
                qty: $qty,
                transactionType: TransactionType::Issue,
                reference: $requisitionItem,
            );

            $requisitionItem->increment('quantity_issued', $qty);
        });
    }
}
