<?php

namespace Modules\Inventory\Classes\Support;

use Modules\Core\Contracts\WardStockConsumptionContract;
use Modules\Core\Support\ModuleAvailability;
use Modules\Inventory\Classes\Services\StockConsumptionService;

class InventoryWardStockConsumption implements WardStockConsumptionContract
{
    public function __construct(
        protected StockConsumptionService $consumption,
    ) {}

    public function consumeFromWard(
        string $itemId,
        string $branchId,
        string $departmentId,
        int $qty,
        ?object $reference = null,
    ): void {
        if (! ModuleAvailability::inventoryEnabled()) {
            return;
        }

        $this->consumption->consumeFromWard(
            itemId: $itemId,
            branchId: $branchId,
            departmentId: $departmentId,
            qty: $qty,
            reference: $reference,
        );
    }
}
