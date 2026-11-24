<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Classes\Services\StockAdjustmentService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;

/**
 * @group Inventory
 *
 * Stock on hand per item, location and lot. Changes go through the ledger:
 * use the adjust action (stock count correction) or the workflow endpoints.
 */
class StockBalanceController extends InventoryApiController
{
    protected string $defaultSort = 'expiry_date';

    public function __construct(protected StockAdjustmentService $adjustments) {}

    public static function model(): string
    {
        return StockBalance::class;
    }

    public function fields(): array
    {
        return ['id', 'inventory_item_id', 'location_type', 'department_id', 'location_id', 'lot_number', 'expiry_date',
            'quantity_on_hand', 'reorder_point', 'unit_id', 'updated_at'];
    }

    public function filters(): array
    {
        return ['inventory_item_id' => 'inventory_item_id', 'location_type' => 'location_type', 'department_id' => 'department_id', 'location_id' => 'location_id'];
    }

    public function sortable(): array
    {
        return ['expiry_date', 'quantity_on_hand', 'updated_at'];
    }

    /**
     * Correct the on-hand quantity after a count (writes an adjustment to the ledger).
     *
     * Lot-tracked, ward and secondary-store balances are corrected through stock takes.
     *
     * @bodyParam quantity integer required The counted quantity. Example: 42
     * @bodyParam reason string required Example: Monthly stock count.
     */
    public function adjust(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->act($id, 'adjust', function (StockBalance $balance) use ($data): StockBalance {
            $this->assertDirectlyAdjustable($balance);
            $this->domain(fn () => $this->adjustments->adjust(
                $balance->inventory_item_id,
                (string) $balance->branch_id,
                $balance->location_type,
                $balance->department_id,
                (int) $data['quantity'],
                $data['reason'] ?? null,
            ));

            return $balance->refresh();
        });
    }

    /**
     * The adjustment service addresses a balance by item, branch, location type
     * and department only; refuse rows it would not resolve to, instead of
     * silently adjusting a different one.
     */
    protected function assertDirectlyAdjustable(StockBalance $balance): void
    {
        $inMainStore = $balance->store_id === null
            || (string) $balance->store_id === (string) Store::mainForBranch((string) $balance->branch_id)->getKey();

        if (! $inMainStore || filled($balance->lot_number) || $balance->expiry_date !== null
            || $balance->location_id !== null || $balance->stock_transfer_id !== null) {
            throw new \DomainException('This balance is lot-, ward- or store-specific; correct it with a stock take.');
        }
    }
}
