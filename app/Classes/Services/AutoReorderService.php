<?php

namespace Modules\Inventory\Classes\Services;

use Illuminate\Support\Collection;
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
            ->with(['inventoryItem', 'branch'])
            ->where('location_type', StockLocationType::Dispensary)
            ->whereNull('department_id')
            ->whereNull('stock_transfer_id')
            ->where('reorder_point', '>', 0)
            ->whereHas('inventoryItem', fn ($builder) => $builder->where('is_active', true))
            ->orderBy('branch_id')
            ->orderBy('inventory_item_id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query
            ->get()
            ->groupBy(fn (StockBalance $balance): string => $balance->inventory_item_id.'|'.$balance->branch_id)
            ->map(function ($group): ?array {
                /** @var Collection<int, StockBalance> $group */
                $balance = $group->first();
                $onHand = (int) $group->sum('quantity_on_hand');
                $reorderPoint = (int) $group->max('reorder_point');

                if ($reorderPoint <= 0 || $onHand > $reorderPoint) {
                    return null;
                }

                return [
                    'inventory_item_id' => (string) $balance->inventory_item_id,
                    'item_name' => $balance->inventoryItem?->name ?? __('Unknown item'),
                    'branch_id' => (string) $balance->branch_id,
                    'branch_name' => $balance->branch?->name ?? '—',
                    'quantity_on_hand' => $onHand,
                    'reorder_point' => $reorderPoint,
                    'quantity_to_order' => max(1, $reorderPoint - $onHand),
                ];
            })
            ->filter()
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
