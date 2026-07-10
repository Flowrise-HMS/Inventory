<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Contracts\StockProviderContract;
use Modules\Inventory\Classes\Support\Feature;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\RequisitionItem;
use Modules\Pharmacy\Models\Medication;

class IssueToPharmacyService
{
    public function __construct(
        protected StockLedgerService $stockLedger,
        protected StockProviderContract $stockProvider,
    ) {}

    public function issue(RequisitionItem $requisitionItem, int $qty): void
    {
        if (! Feature::pharmacyProcurementEnabled()) {
            throw new \RuntimeException('Inventory pharmacy procurement is disabled.');
        }

        $inventoryItem = $requisitionItem->inventoryItem;
        $medication = Medication::findOrFail($inventoryItem->medication_id);
        $requisition = $requisitionItem->requisition;

        DB::transaction(function () use ($requisitionItem, $requisition, $inventoryItem, $medication, $qty): void {
            $inventoryQty = $qty;
            $pharmacyQty = $qty;

            if ($inventoryItem->unit_id !== $medication->stock_unit_id) {
                if ($medication->units_per_stock_unit) {
                    $pharmacyQty = $qty * (int) $medication->units_per_stock_unit;
                } else {
                    throw new \RuntimeException(
                        "Unit mismatch: Inventory item unit ({$inventoryItem->unit_id}) ".
                        "does not match medication stock unit ({$medication->stock_unit_id})."
                    );
                }
            }

            $this->stockLedger->lockAndDecrement(
                itemId: $inventoryItem->id,
                branchId: $requisition->branch_id,
                locationType: StockLocationType::Dispensary,
                departmentId: null,
                stockTransferId: null,
                qty: $inventoryQty,
                transactionType: TransactionType::Issue,
                reference: $requisitionItem,
            );

            $this->stockProvider->incrementWithReference(
                branchId: $requisition->branch_id,
                itemId: $medication->id,
                quantity: $pharmacyQty,
                reason: 'receive',
                referenceType: 'requisition_item',
                referenceId: $requisitionItem->id,
            );

            $requisitionItem->increment('quantity_issued', $inventoryQty);
        });
    }
}
