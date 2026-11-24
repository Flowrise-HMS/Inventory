<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;

class StockAdjustmentService
{
    public function __construct(
        protected StockLedgerService $stockLedger,
    ) {}

    /**
     * Set the quantity of exactly this balance row (store, lot, expiry, ward).
     * Prefer this over adjust(), which can only address the main store's or a
     * department's row without a lot.
     */
    public function adjustBalance(StockBalance $balance, int $newQty): void
    {
        if ($newQty < 0) {
            throw new \RuntimeException('Stock cannot be negative.');
        }

        DB::transaction(function () use ($balance, $newQty): void {
            $current = StockBalance::query()->lockForUpdate()->findOrFail($balance->getKey());
            $delta = $newQty - (int) $current->quantity_on_hand;

            if ($delta === 0) {
                throw new \RuntimeException('New quantity matches current on-hand quantity.');
            }

            $row = [
                'itemId' => (string) $current->inventory_item_id,
                'branchId' => (string) $current->branch_id,
                'locationType' => $current->location_type,
                'departmentId' => $current->department_id,
                'stockTransferId' => $current->stock_transfer_id,
                'qty' => abs($delta),
                'transactionType' => TransactionType::Adjust,
                'lotNumber' => $current->lot_number,
                'expiryDate' => $current->expiry_date?->toDateString(),
                'locationId' => $current->location_id,
                'storeId' => $current->store_id,
            ];

            $delta > 0
                ? $this->stockLedger->lockAndIncrement(...$row)
                : $this->stockLedger->lockAndDecrementExact(...$row);
        });
    }

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

    /**
     * Adjust one lot of store stock by a signed delta (used by stock-take
     * approval). Expired lots may be written down, as with any adjustment.
     */
    public function adjustStoreLot(
        string $itemId,
        string $storeId,
        ?string $lotNumber,
        ?string $expiryDate,
        int $delta,
        object $reference,
    ): InventoryTransaction {
        if ($delta === 0) {
            throw new \RuntimeException('Nothing to adjust.');
        }

        $store = Store::query()->findOrFail($storeId);

        return DB::transaction(function () use ($itemId, $store, $lotNumber, $expiryDate, $delta, $reference): InventoryTransaction {
            if ($delta > 0) {
                $this->stockLedger->lockAndIncrement(
                    itemId: $itemId,
                    branchId: (string) $store->branch_id,
                    locationType: StockLocationType::Dispensary,
                    departmentId: null,
                    stockTransferId: null,
                    qty: $delta,
                    transactionType: TransactionType::Adjust,
                    reference: $reference,
                    lotNumber: $lotNumber,
                    expiryDate: $expiryDate,
                    storeId: (string) $store->getKey(),
                );
            } else {
                $this->stockLedger->lockAndDecrementExact(
                    itemId: $itemId,
                    branchId: (string) $store->branch_id,
                    locationType: StockLocationType::Dispensary,
                    qty: abs($delta),
                    transactionType: TransactionType::Adjust,
                    reference: $reference,
                    lotNumber: $lotNumber,
                    expiryDate: $expiryDate,
                    storeId: (string) $store->getKey(),
                );
            }

            return InventoryTransaction::query()
                ->where('reference_type', $reference::class)
                ->where('reference_id', $reference->getKey())
                ->latest('created_at')
                ->firstOrFail();
        });
    }
}
