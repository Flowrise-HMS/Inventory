<?php

namespace Modules\Inventory\Classes\Support;

use Illuminate\Support\Facades\DB;
use Modules\Core\Contracts\InventoryLowStockProviderContract;
use Modules\Core\Support\ModuleAvailability;
use Modules\Inventory\Models\StockBalance;

class InventoryLowStockProvider implements InventoryLowStockProviderContract
{
    /**
     * @return list<array<string, mixed>>
     */
    public function items(?string $branchId = null, int $limit = 10): array
    {
        if (! ModuleAvailability::inventoryEnabled()) {
            return [];
        }

        $query = StockBalance::query()
            ->with(['inventoryItem', 'branch'])
            ->whereColumn('quantity_on_hand', '<=', DB::raw('COALESCE(reorder_point, 0)'))
            ->where('quantity_on_hand', '>', 0)
            ->orderByRaw('(quantity_on_hand * 1.0 / NULLIF(reorder_point, 0)) asc');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (StockBalance $balance): array => [
                'source' => 'inventory',
                'id' => (string) $balance->id,
                'name' => $balance->inventoryItem?->name ?? '-',
                'branch' => $balance->branch?->name ?? '-',
                'location' => $balance->location_type?->getLabel() ?? (string) $balance->location_type,
                'quantity_on_hand' => (int) $balance->quantity_on_hand,
                'reorder_point' => (int) ($balance->reorder_point ?? 0),
            ])
            ->all();
    }
}
