<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockBalance;

class StockLedgerService
{
    /**
     * Add opening/manual dispensary stock for an item at a branch, optionally
     * setting a reorder point. Used by the InventoryItem create form and the
     * inline "Add Stock" table action.
     */
    public function addOpeningStock(
        string $itemId,
        string $branchId,
        int $qty,
        ?int $reorderPoint = null,
    ): StockBalance {
        return DB::transaction(function () use ($itemId, $branchId, $qty, $reorderPoint) {
            $balance = $qty > 0
                ? $this->lockAndIncrement(
                    itemId: $itemId,
                    branchId: $branchId,
                    locationType: StockLocationType::Dispensary,
                    departmentId: null,
                    stockTransferId: null,
                    qty: $qty,
                    transactionType: TransactionType::Adjust,
                )
                : $this->lockBalance($itemId, $branchId, StockLocationType::Dispensary, null, null);

            if ($reorderPoint !== null) {
                $balance->update(['reorder_point' => $reorderPoint]);
            }

            return $balance->fresh();
        });
    }

    public function lockBalanceRow(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        ?string $stockTransferId,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
    ): StockBalance {
        return $this->lockBalance($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $lotNumber, $expiryDate);
    }

    public function lockAndDecrement(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        ?string $stockTransferId,
        int $qty,
        TransactionType $transactionType,
        ?object $reference = null,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
    ): StockBalance {
        if ($lotNumber !== null || $expiryDate !== null) {
            return $this->decrementSpecificLot(
                itemId: $itemId,
                branchId: $branchId,
                locationType: $locationType,
                departmentId: $departmentId,
                stockTransferId: $stockTransferId,
                qty: $qty,
                transactionType: $transactionType,
                reference: $reference,
                lotNumber: $lotNumber,
                expiryDate: $expiryDate,
            );
        }

        $allocations = $this->decrementFefo(
            itemId: $itemId,
            branchId: $branchId,
            locationType: $locationType,
            departmentId: $departmentId,
            stockTransferId: $stockTransferId,
            qty: $qty,
            transactionType: $transactionType,
            reference: $reference,
        );

        return StockBalance::query()->findOrFail($allocations[0]['stock_balance_id']);
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
        ?string $lotNumber = null,
        ?string $expiryDate = null,
    ): StockBalance {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $qty, $transactionType, $reference, $lotNumber, $expiryDate) {
            $balance = $this->lockBalance($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $lotNumber, $expiryDate);

            $balance->quantity_on_hand += $qty;
            $balance->save();

            $this->writeTransaction($balance, $qty, $transactionType, $reference, $branchId);

            return $balance->fresh();
        });
    }

    /**
     * @return list<array{stock_balance_id: string, lot_number: ?string, expiry_date: ?string, quantity: int}>
     */
    public function decrementFefo(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        ?string $stockTransferId,
        int $qty,
        TransactionType $transactionType,
        ?object $reference = null,
    ): array {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $qty, $transactionType, $reference) {
            $remaining = $qty;
            $allocations = [];

            $balances = StockBalance::query()
                ->where('inventory_item_id', $itemId)
                ->where('branch_id', $branchId)
                ->where('location_type', $locationType->value)
                ->where('department_id', $departmentId)
                ->where('stock_transfer_id', $stockTransferId)
                ->where('quantity_on_hand', '>', 0)
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $available = (int) $balances->sum('quantity_on_hand');

            if ($available < $qty) {
                throw new \RuntimeException('Insufficient stock on hand.');
            }

            foreach ($balances as $balance) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, (int) $balance->quantity_on_hand);
                $balance->quantity_on_hand -= $take;
                $balance->save();

                $this->writeTransaction($balance, -$take, $transactionType, $reference, $branchId);

                $allocations[] = [
                    'stock_balance_id' => (string) $balance->id,
                    'lot_number' => $balance->lot_number,
                    'expiry_date' => $balance->expiry_date?->toDateString(),
                    'quantity' => $take,
                ];

                $remaining -= $take;
            }

            return $allocations;
        });
    }

    public function transferQuantity(
        string $itemId,
        string $fromBranchId,
        StockLocationType $fromLocation,
        ?string $fromDepartmentId,
        ?string $fromStockTransferId,
        string $toBranchId,
        StockLocationType $toLocation,
        ?string $toDepartmentId,
        ?string $toStockTransferId,
        int $qty,
        TransactionType $transactionType,
        ?object $reference = null,
    ): void {
        DB::transaction(function () use ($itemId, $fromBranchId, $fromLocation, $fromDepartmentId, $fromStockTransferId, $toBranchId, $toLocation, $toDepartmentId, $toStockTransferId, $qty, $transactionType, $reference): void {
            $allocations = $this->decrementFefo(
                itemId: $itemId,
                branchId: $fromBranchId,
                locationType: $fromLocation,
                departmentId: $fromDepartmentId,
                stockTransferId: $fromStockTransferId,
                qty: $qty,
                transactionType: $transactionType,
                reference: $reference,
            );

            foreach ($allocations as $allocation) {
                $this->lockAndIncrement(
                    itemId: $itemId,
                    branchId: $toBranchId,
                    locationType: $toLocation,
                    departmentId: $toDepartmentId,
                    stockTransferId: $toStockTransferId,
                    qty: $allocation['quantity'],
                    transactionType: $transactionType,
                    reference: $reference,
                    lotNumber: $allocation['lot_number'],
                    expiryDate: $allocation['expiry_date'],
                );
            }
        });
    }

    protected function decrementSpecificLot(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        ?string $stockTransferId,
        int $qty,
        TransactionType $transactionType,
        ?object $reference,
        ?string $lotNumber,
        ?string $expiryDate,
    ): StockBalance {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $qty, $transactionType, $reference, $lotNumber, $expiryDate) {
            $balance = $this->lockBalance($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $lotNumber, $expiryDate);

            if ($balance->quantity_on_hand < $qty) {
                throw new \RuntimeException('Insufficient stock on hand.');
            }

            $balance->quantity_on_hand -= $qty;
            $balance->save();

            $this->writeTransaction($balance, -$qty, $transactionType, $reference, $branchId);

            return $balance->fresh();
        });
    }

    protected function lockBalance(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        ?string $departmentId,
        ?string $stockTransferId,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
    ): StockBalance {
        $normalizedLot = $this->normalizeLotNumber($lotNumber);
        $normalizedExpiry = $this->normalizeExpiryDate($expiryDate);

        $balance = StockBalance::query()
            ->where('inventory_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('location_type', $locationType->value)
            ->where('department_id', $departmentId)
            ->where('stock_transfer_id', $stockTransferId)
            ->when(
                $normalizedLot === null,
                fn ($query) => $query->whereNull('lot_number'),
                fn ($query) => $query->where('lot_number', $normalizedLot),
            )
            ->when(
                $normalizedExpiry === null,
                fn ($query) => $query->whereNull('expiry_date'),
                fn ($query) => $query->whereDate('expiry_date', $normalizedExpiry),
            )
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
                'lot_number' => $normalizedLot,
                'expiry_date' => $normalizedExpiry,
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
    ): InventoryTransaction {
        return InventoryTransaction::create([
            'inventory_item_id' => $balance->inventory_item_id,
            'delta' => $delta,
            'quantity_after' => $balance->quantity_on_hand,
            'transaction_type' => $transactionType->value,
            'lot_number' => $balance->lot_number,
            'expiry_date' => $balance->expiry_date,
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

    protected function normalizeLotNumber(?string $lotNumber): ?string
    {
        if ($lotNumber === null) {
            return null;
        }

        $trimmed = trim($lotNumber);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function normalizeExpiryDate(null|string|\DateTimeInterface $expiryDate): ?string
    {
        if ($expiryDate === null || $expiryDate === '') {
            return null;
        }

        return Carbon::parse($expiryDate)->toDateString();
    }
}
