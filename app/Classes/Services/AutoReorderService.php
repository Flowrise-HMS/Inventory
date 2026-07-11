<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\StockBalance;

class AutoReorderService
{
    /**
     * @return list<array{
     *     inventory_item_id: string,
     *     item_name: string,
     *     branch_id: string,
     *     branch_name: string,
     *     quantity_on_hand: int,
     *     reorder_point: int,
     *     quantity_to_order: int
     * }>
     */
    public function suggestions(?string $branchId = null): array
    {
        $query = StockBalance::query()
            ->select([
                'stock_balances.inventory_item_id',
                'stock_balances.branch_id',
                DB::raw('SUM(stock_balances.quantity_on_hand) as quantity_on_hand'),
                DB::raw('MAX(stock_balances.reorder_point) as reorder_point'),
                'inventory_items.name as item_name',
                'branches.name as branch_name',
            ])
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_balances.inventory_item_id')
            ->join('branches', 'branches.id', '=', 'stock_balances.branch_id')
            ->where('stock_balances.location_type', StockLocationType::Dispensary)
            ->whereNull('stock_balances.department_id')
            ->whereNull('stock_balances.stock_transfer_id')
            ->where('inventory_items.is_active', true)
            ->groupBy(
                'stock_balances.inventory_item_id',
                'stock_balances.branch_id',
                'inventory_items.name',
                'branches.name',
            )
            ->havingRaw('MAX(stock_balances.reorder_point) > 0')
            ->havingRaw('SUM(stock_balances.quantity_on_hand) <= MAX(stock_balances.reorder_point)')
            ->orderBy('stock_balances.branch_id')
            ->orderBy('stock_balances.inventory_item_id');

        if ($branchId !== null) {
            $query->where('stock_balances.branch_id', $branchId);
        }

        return $query
            ->get()
            ->map(function (object $row): array {
                $onHand = (int) $row->quantity_on_hand;
                $reorderPoint = (int) $row->reorder_point;

                return [
                    'inventory_item_id' => (string) $row->inventory_item_id,
                    'item_name' => (string) $row->item_name,
                    'branch_id' => (string) $row->branch_id,
                    'branch_name' => (string) $row->branch_name,
                    'quantity_on_hand' => $onHand,
                    'reorder_point' => $reorderPoint,
                    'quantity_to_order' => max(1, $reorderPoint - $onHand),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{inventory_item_id: string, quantity_ordered: int}>  $items
     */
    public function createDraftPurchaseOrder(string $supplierId, string $branchId, array $items): PurchaseOrder
    {
        if ($items === []) {
            throw new \InvalidArgumentException('At least one reorder line is required.');
        }

        return app(PurchaseOrderService::class)->create([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'notes' => __('Generated from low-stock reorder suggestions.'),
            'items' => array_map(
                fn (array $item): array => [
                    'inventory_item_id' => $item['inventory_item_id'],
                    'quantity_ordered' => (int) $item['quantity_ordered'],
                ],
                $items,
            ),
        ]);
    }
}
