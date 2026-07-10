<?php

namespace Modules\Inventory\Classes\Support;

use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\WardMedicationConsumptionContract;
use Modules\Core\Support\ModuleAvailability;
use Modules\Inventory\Classes\Services\StockConsumptionService;
use Modules\Inventory\Models\InventoryItem;

class InventoryWardMedicationConsumption implements WardMedicationConsumptionContract
{
    public function __construct(
        protected StockConsumptionService $consumption,
    ) {}

    public function consumeMedicationFromWard(
        string $medicationId,
        string $branchId,
        string $departmentId,
        int $qty,
        ?object $reference = null,
    ): void {
        if (! ModuleAvailability::inventoryEnabled() || ! Feature::wardRequisitionsEnabled() || $qty <= 0) {
            return;
        }

        $inventoryItem = InventoryItem::query()
            ->where('medication_id', $medicationId)
            ->where('is_active', true)
            ->first();

        if ($inventoryItem === null) {
            return;
        }

        try {
            $this->consumption->consumeFromWard(
                itemId: $inventoryItem->id,
                branchId: $branchId,
                departmentId: $departmentId,
                qty: $qty,
                reference: $reference,
            );
        } catch (\Throwable $exception) {
            Log::warning('Ward stock consumption failed after MAR dose.', [
                'medication_id' => $medicationId,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'quantity' => $qty,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
