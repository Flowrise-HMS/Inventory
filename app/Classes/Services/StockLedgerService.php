<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockBalance;

class StockLedgerService
{
    public function lockAndDecrement(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        ?string $stockTransferId,
        int $qty,
        TransactionType $transactionType,
        ?object $reference = null,
    ): StockBalance {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $qty, $transactionType, $reference) {
            $balance = $this->lockBalance($itemId, $branchId, $locationType, $departmentId, $stockTransferId);

            if ($balance->quantity_on_hand < $qty) {
                throw new \RuntimeException('Insufficient stock on hand.');
            }

            $balance->quantity_on_hand -= $qty;
            $balance->save();

            $this->writeTransaction($balance, -$qty, $transactionType, $reference, $branchId);

            return $balance->fresh();
        });
    }

    public function lockAndIncrement(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        ?string $stockTransferId,
        int $qty,
        TransactionType $transactionType,
        ?object $reference = null,
    ): StockBalance {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $qty, $transactionType, $reference) {
            $balance = $this->lockBalance($itemId, $branchId, $locationType, $departmentId, $stockTransferId);

            $balance->quantity_on_hand += $qty;
            $balance->save();

            $this->writeTransaction($balance, $qty, $transactionType, $reference, $branchId);

            return $balance->fresh();
        });
    }

    protected function lockBalance(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        ?string $stockTransferId,
    ): StockBalance {
        $balance = StockBalance::query()
            ->where('inventory_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('location_type', $locationType->value)
            ->where('department_id', $departmentId)
            ->where('stock_transfer_id', $stockTransferId)
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            $item = InventoryItem::findOrFail($itemId);

            $balance = StockBalance::create([
                'inventory_item_id' => $itemId,
                'branch_id' => $branchId,
                'location_type' => $locationType->value,
                'department_id' => $departmentId,
                'stock_transfer_id' => $stockTransferId,
                'quantity_on_hand' => 0,
                'unit_id' => $item->unit_id,
            ]);
        }

        return $balance;
    }

    protected function writeTransaction(
        StockBalance $balance,
        int $delta,
        TransactionType $transactionType,
        ?object $reference,
        string $branchId,
    ): void {
        InventoryTransaction::create([
            'inventory_item_id' => $balance->inventory_item_id,
            'delta' => $delta,
            'quantity_after' => $balance->quantity_on_hand,
            'transaction_type' => $transactionType->value,
            'from_location_type' => null,
            'from_location_id' => null,
            'to_location_type' => null,
            'to_location_id' => null,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'unit_label_snapshot' => $balance->unit?->label,
            'performed_by' => Auth::id(),
            'branch_id' => $branchId,
        ]);
    }
}
