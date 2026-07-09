<?php

namespace Modules\Inventory\Classes\Services;

use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;

class StockAdjustmentService
{
    public function __construct(
        protected StockLedgerService $stockLedger,
    ) {}

    public function adjust(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        int $newQty,
        ?string $reason = null,
    ): void {
        $balance = $this->stockLedger->lockAndDecrement(
            itemId: $itemId,
            branchId: $branchId,
            locationType: $locationType,
            departmentId: $departmentId,
            stockTransferId: null,
            qty: 0,
            transactionType: TransactionType::Adjust,
            reference: null,
        );

        $delta = $newQty - $balance->quantity_on_hand;

        if ($delta > 0) {
            $this->stockLedger->lockAndIncrement(
                itemId: $itemId,
                branchId: $branchId,
                locationType: $locationType,
                departmentId: $departmentId,
                stockTransferId: null,
                qty: $delta,
                transactionType: TransactionType::Adjust,
                reference: null,
            );
        } elseif ($delta < 0) {
            $this->stockLedger->lockAndDecrement(
                itemId: $itemId,
                branchId: $branchId,
                locationType: $locationType,
                departmentId: $departmentId,
                stockTransferId: null,
                qty: abs($delta),
                transactionType: TransactionType::Adjust,
                reference: null,
            );
        }
    }
}
