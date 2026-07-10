<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryTransaction;

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
    ): InventoryTransaction {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $newQty): InventoryTransaction {
            $balance = $this->stockLedger->lockBalanceRow(
                itemId: $itemId,
                branchId: $branchId,
                locationType: $locationType,
                departmentId: $departmentId,
                stockTransferId: null,
            );

            $delta = $newQty - $balance->quantity_on_hand;

            if ($delta === 0) {
                throw new \RuntimeException('New quantity matches current on-hand quantity.');
            }

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
            } else {
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

            return InventoryTransaction::query()
                ->where('inventory_item_id', $itemId)
                ->where('branch_id', $branchId)
                ->where('transaction_type', TransactionType::Adjust)
                ->latest('created_at')
                ->firstOrFail();
        });
    }
}
