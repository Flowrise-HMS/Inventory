<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Classes\Support\Feature;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;

class StockConsumptionService
{
    public function __construct(
        protected StockLedgerService $stockLedger,
    ) {}

    public function consumeFromWard(
        string $itemId,
        string $branchId,
        string $departmentId,
        int $qty,
        ?object $reference = null,
    ): void {
        if (! Feature::wardRequisitionsEnabled()) {
            throw new \RuntimeException('Inventory ward requisitions are disabled.');
        }

        if ($qty <= 0) {
            throw new \InvalidArgumentException('Consumption quantity must be positive.');
        }

        DB::transaction(function () use ($itemId, $branchId, $departmentId, $qty, $reference): void {
            $this->stockLedger->lockAndDecrement(
                itemId: $itemId,
                branchId: $branchId,
                locationType: StockLocationType::Ward,
                departmentId: $departmentId,
                stockTransferId: null,
                qty: $qty,
                transactionType: TransactionType::Consume,
                reference: $reference,
            );
        });
    }
}
