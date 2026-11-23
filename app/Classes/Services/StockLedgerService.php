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
use Modules\Inventory\Models\Store;

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
        ?string $storeId = null,
    ): StockBalance {
        return DB::transaction(function () use ($itemId, $branchId, $qty, $reorderPoint, $storeId) {
            $balance = $qty > 0
                ? $this->lockAndIncrement(
                    itemId: $itemId,
                    branchId: $branchId,
                    locationType: StockLocationType::Dispensary,
                    departmentId: null,
                    stockTransferId: null,
                    qty: $qty,
                    transactionType: TransactionType::Adjust,
                    storeId: $storeId,
                )
                : $this->lockBalance($itemId, $branchId, StockLocationType::Dispensary, null, null, storeId: $storeId);

            if ($reorderPoint !== null) {
                // Apply to every lot row of the item in this store, keeping any max level.
                $levels = app(StoreStockLevelService::class);
                $maxLevel = $levels->levels($itemId, (string) $balance->store_id)['max_level'];
                $levels->setLevels(
                    $itemId,
                    (string) $balance->store_id,
                    $reorderPoint,
                    $maxLevel !== null && $maxLevel >= $reorderPoint ? $maxLevel : null,
                );
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
        ?string $locationId = null,
        ?string $storeId = null,
    ): StockBalance {
        return $this->lockBalance($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $lotNumber, $expiryDate, $locationId, $storeId);
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
        ?string $locationId = null,
        ?string $storeId = null,
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
                locationId: $locationId,
                storeId: $storeId,
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
            locationId: $locationId,
            storeId: $storeId,
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
        ?string $locationId = null,
        ?string $storeId = null,
    ): StockBalance {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $qty, $transactionType, $reference, $lotNumber, $expiryDate, $locationId, $storeId) {
            $balance = $this->lockBalance($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $lotNumber, $expiryDate, $locationId, $storeId);

            $balance->quantity_on_hand += $qty;
            $balance->save();

            $this->writeTransaction($balance, $qty, $transactionType, $reference, $branchId);

            return $balance->fresh();
        });
    }

    /**
     * Decrement exactly one row (item, store, lot, expiry), including the
     * no-lot row, without FEFO spilling over into other lots.
     */
    public function lockAndDecrementExact(
        string $itemId,
        string $branchId,
        StockLocationType $locationType,
        int $qty,
        TransactionType $transactionType,
        ?object $reference = null,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
        ?string $storeId = null,
    ): StockBalance {
        return $this->decrementSpecificLot(
            itemId: $itemId,
            branchId: $branchId,
            locationType: $locationType,
            departmentId: null,
            stockTransferId: null,
            qty: $qty,
            transactionType: $transactionType,
            reference: $reference,
            lotNumber: $lotNumber,
            expiryDate: $expiryDate,
            storeId: $storeId,
        );
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
        ?string $locationId = null,
        ?string $storeId = null,
    ): array {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $qty, $transactionType, $reference, $locationId, $storeId) {
            $storeId = $this->resolveStoreId($locationType, $branchId, $storeId);
            $remaining = $qty;
            $allocations = [];

            $today = now()->toDateString();
            $excludeExpired = ! $transactionType->mayConsumeExpiredStock();

            $candidates = StockBalance::query()
                ->where('inventory_item_id', $itemId)
                ->where('branch_id', $branchId)
                ->when(
                    $storeId === null,
                    fn ($query) => $query->whereNull('store_id'),
                    fn ($query) => $query->where('store_id', $storeId),
                )
                ->where('location_type', $locationType->value)
                ->where('department_id', $departmentId)
                ->when(
                    $locationId === null,
                    fn ($query) => $query->whereNull('location_id'),
                    fn ($query) => $query->where('location_id', $locationId),
                )
                ->where('stock_transfer_id', $stockTransferId)
                ->where('quantity_on_hand', '>', 0);

            // FEFO orders by earliest expiry, so without this filter an expired
            // lot sorts FIRST and is the first thing issued. Lots with no expiry
            // date are non-expiring and stay eligible (sorted last). Adjustments
            // keep access to expired lots so they can still be written off.
            $balances = (clone $candidates)
                ->when($excludeExpired, fn ($query) => $query->where(
                    fn ($inner) => $inner->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today)
                ))
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $available = (int) $balances->sum('quantity_on_hand');

            if ($available < $qty) {
                $expired = $excludeExpired
                    ? (int) (clone $candidates)
                        ->whereNotNull('expiry_date')
                        ->where('expiry_date', '<', $today)
                        ->sum('quantity_on_hand')
                    : 0;

                if ($expired > 0) {
                    throw new \RuntimeException(sprintf(
                        'Insufficient unexpired stock on hand: %d available, %d required (%d expired unit(s) excluded).',
                        $available,
                        $qty,
                        $expired,
                    ));
                }

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
        ?string $fromLocationId = null,
        ?string $toLocationId = null,
        ?string $fromStoreId = null,
        ?string $toStoreId = null,
    ): void {
        DB::transaction(function () use ($itemId, $fromBranchId, $fromLocation, $fromDepartmentId, $fromStockTransferId, $toBranchId, $toLocation, $toDepartmentId, $toStockTransferId, $qty, $transactionType, $reference, $fromLocationId, $toLocationId, $fromStoreId, $toStoreId): void {
            $allocations = $this->decrementFefo(
                itemId: $itemId,
                branchId: $fromBranchId,
                locationType: $fromLocation,
                departmentId: $fromDepartmentId,
                stockTransferId: $fromStockTransferId,
                qty: $qty,
                transactionType: $transactionType,
                reference: $reference,
                locationId: $fromLocationId,
                storeId: $fromStoreId,
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
                    locationId: $toLocationId,
                    storeId: $toStoreId,
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
        ?string $locationId = null,
        ?string $storeId = null,
    ): StockBalance {
        return DB::transaction(function () use ($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $qty, $transactionType, $reference, $lotNumber, $expiryDate, $locationId, $storeId) {
            $balance = $this->lockBalance($itemId, $branchId, $locationType, $departmentId, $stockTransferId, $lotNumber, $expiryDate, $locationId, $storeId);

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
        ?string $locationId = null,
        ?string $storeId = null,
    ): StockBalance {
        $storeId = $this->resolveStoreId($locationType, $branchId, $storeId);
        $normalizedLot = $this->normalizeLotNumber($lotNumber);
        $normalizedExpiry = $this->normalizeExpiryDate($expiryDate);

        $balance = StockBalance::query()
            ->where('inventory_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->when(
                $storeId === null,
                fn ($query) => $query->whereNull('store_id'),
                fn ($query) => $query->where('store_id', $storeId),
            )
            ->where('location_type', $locationType->value)
            ->where('department_id', $departmentId)
            ->when(
                $locationId === null,
                fn ($query) => $query->whereNull('location_id'),
                fn ($query) => $query->where('location_id', $locationId),
            )
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
                'store_id' => $storeId,
                'location_type' => $locationType->value,
                'department_id' => $departmentId,
                'location_id' => $locationId,
                'stock_transfer_id' => $stockTransferId,
                'lot_number' => $normalizedLot,
                'expiry_date' => $normalizedExpiry,
                'quantity_on_hand' => 0,
                'unit_id' => $item->unit_id,
            ]);
        }

        return $balance;
    }

    /**
     * Central (dispensary) stock always lives in a store: an explicit store
     * must belong to the branch, and no store means the branch's main store.
     * Ward and in-transit stock never carry a store.
     */
    protected function resolveStoreId(StockLocationType $locationType, string $branchId, ?string $storeId): ?string
    {
        if ($locationType !== StockLocationType::Dispensary) {
            return null;
        }

        if ($storeId === null) {
            return (string) Store::mainForBranch($branchId)->getKey();
        }

        $belongsToBranch = Store::query()
            ->whereKey($storeId)
            ->where('branch_id', $branchId)
            ->exists();

        if (! $belongsToBranch) {
            throw new \RuntimeException('The selected store does not belong to this branch.');
        }

        return $storeId;
    }

    protected function writeTransaction(
        StockBalance $balance,
        int $delta,
        TransactionType $transactionType,
        ?object $reference,
        string $branchId,
    ): InventoryTransaction {
        if ($balance->store_id !== null && $balance->location_type === StockLocationType::Dispensary) {
            app(StoreStockLevelService::class)->queueEvaluation((string) $balance->inventory_item_id, (string) $balance->store_id);
        }

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
