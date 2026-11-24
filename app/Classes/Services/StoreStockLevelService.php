<?php

namespace Modules\Inventory\Classes\Services;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\UserRole;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Filament\Clusters\Inventory\Pages\StoresDashboard;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\StoreStockAlert;

/**
 * Stock levels for items in a store and the alerts that come with them.
 *
 * An item's levels in a store are read across all of its central
 * (dispensary) rows there, because lots each have their own row: on hand is
 * the sum, reorder point and max level are the highest value set on any row.
 */
class StoreStockLevelService
{
    /**
     * @return array{on_hand: int, reorder_point: int, max_level: ?int}
     */
    public function levels(string $itemId, string $storeId): array
    {
        $row = $this->centralRows($itemId, $storeId)
            ->selectRaw('COALESCE(SUM(quantity_on_hand), 0) as on_hand, MAX(reorder_point) as reorder_point, MAX(max_level) as max_level')
            ->toBase()
            ->first();

        return [
            'on_hand' => (int) ($row->on_hand ?? 0),
            'reorder_point' => (int) ($row->reorder_point ?? 0),
            'max_level' => $row?->max_level !== null ? (int) $row->max_level : null,
        ];
    }

    /**
     * Set the reorder point and max level for an item in a store, on every
     * row of it there (so lots can't disagree).
     */
    public function setLevels(string $itemId, string $storeId, ?int $reorderPoint, ?int $maxLevel): void
    {
        if ($reorderPoint !== null && $reorderPoint < 0) {
            throw new \RuntimeException('The reorder point cannot be negative.');
        }

        if ($maxLevel !== null && $maxLevel < 1) {
            throw new \RuntimeException('The maximum level must be at least 1.');
        }

        if ($maxLevel !== null && $reorderPoint !== null && $maxLevel < $reorderPoint) {
            throw new \RuntimeException('The maximum level cannot be below the reorder point.');
        }

        $store = Store::query()->findOrFail($storeId);

        DB::transaction(function () use ($itemId, $store, $reorderPoint, $maxLevel): void {
            if (! $this->centralRows($itemId, (string) $store->getKey())->exists()) {
                app(StockLedgerService::class)->lockBalanceRow(
                    itemId: $itemId,
                    branchId: (string) $store->branch_id,
                    locationType: StockLocationType::Dispensary,
                    departmentId: null,
                    stockTransferId: null,
                    storeId: (string) $store->getKey(),
                );
            }

            $this->centralRows($itemId, (string) $store->getKey())->update([
                'reorder_point' => $reorderPoint,
                'max_level' => $maxLevel,
            ]);
        });

        $this->evaluate($itemId, (string) $store->getKey());
    }

    /**
     * How much to reorder: up to the max level when one is set, otherwise
     * back up to the reorder point. Always at least 1.
     */
    public function suggestedOrderQuantity(int $onHand, int $reorderPoint, ?int $maxLevel): int
    {
        $target = $maxLevel !== null && $maxLevel > 0 ? $maxLevel : $reorderPoint;

        return max(1, $target - $onHand);
    }

    /**
     * Items at or below their reorder point, one row per store and item.
     *
     * @param  list<string>|null  $storeIds  null for every store
     * @return Collection<int, array{key: string, store_id: string, store_name: string, branch_id: string, branch_name: string, inventory_item_id: string, item_name: string, quantity_on_hand: int, reorder_point: int, max_level: ?int, quantity_to_order: int, is_out: bool}>
     */
    public function lowStock(?array $storeIds = null, ?string $branchId = null): Collection
    {
        return StockBalance::query()
            ->toBase()
            ->select([
                'stock_balances.store_id',
                'stock_balances.inventory_item_id',
                'stores.name as store_name',
                'stores.branch_id',
                'branches.name as branch_name',
                'inventory_items.name as item_name',
            ])
            ->selectRaw('SUM(stock_balances.quantity_on_hand) as quantity_on_hand')
            ->selectRaw('MAX(stock_balances.reorder_point) as reorder_point')
            ->selectRaw('MAX(stock_balances.max_level) as max_level')
            ->join('stores', 'stores.id', '=', 'stock_balances.store_id')
            ->join('branches', 'branches.id', '=', 'stores.branch_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_balances.inventory_item_id')
            ->where('stock_balances.location_type', StockLocationType::Dispensary->value)
            ->whereNull('stock_balances.department_id')
            ->whereNull('stock_balances.stock_transfer_id')
            ->where('inventory_items.is_active', true)
            ->where('stores.is_active', true)
            ->when($storeIds !== null, fn ($q) => $q->whereIn('stock_balances.store_id', $storeIds === [] ? ['__none__'] : $storeIds))
            ->when($branchId !== null, fn ($q) => $q->where('stores.branch_id', $branchId))
            ->groupBy('stock_balances.store_id', 'stock_balances.inventory_item_id', 'stores.name', 'stores.branch_id', 'branches.name', 'inventory_items.name')
            ->havingRaw('MAX(stock_balances.reorder_point) > 0')
            ->havingRaw('SUM(stock_balances.quantity_on_hand) <= MAX(stock_balances.reorder_point)')
            ->orderByRaw('SUM(stock_balances.quantity_on_hand) * 1.0 / MAX(stock_balances.reorder_point) asc')
            ->orderBy('inventory_items.name')
            ->get()
            ->map(function (object $row): array {
                $onHand = (int) $row->quantity_on_hand;
                $reorderPoint = (int) $row->reorder_point;
                $maxLevel = $row->max_level !== null ? (int) $row->max_level : null;

                return [
                    'key' => $row->store_id.'|'.$row->inventory_item_id,
                    'store_id' => (string) $row->store_id,
                    'store_name' => (string) $row->store_name,
                    'branch_id' => (string) $row->branch_id,
                    'branch_name' => (string) $row->branch_name,
                    'inventory_item_id' => (string) $row->inventory_item_id,
                    'item_name' => (string) $row->item_name,
                    'quantity_on_hand' => $onHand,
                    'reorder_point' => $reorderPoint,
                    'max_level' => $maxLevel,
                    'quantity_to_order' => $this->suggestedOrderQuantity($onHand, $reorderPoint, $maxLevel),
                    'is_out' => $onHand <= 0,
                ];
            })
            ->values();
    }

    /**
     * Central lots with stock that expire within the warning window, or have
     * already expired and are still on the shelf.
     *
     * @param  list<string>|null  $storeIds  null for every store
     */
    public function expiringQuery(?array $storeIds = null, ?int $days = null): Builder
    {
        $days ??= $this->expiryWarningDays();

        return StockBalance::query()
            ->with(['inventoryItem', 'store', 'unit'])
            ->where('location_type', StockLocationType::Dispensary->value)
            ->whereNull('department_id')
            ->whereNull('stock_transfer_id')
            ->whereNotNull('store_id')
            ->where('quantity_on_hand', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->when($storeIds !== null, fn (Builder $q) => $q->whereIn('store_id', $storeIds === [] ? ['__none__'] : $storeIds))
            ->orderBy('expiry_date');
    }

    /**
     * @param  list<string>|null  $storeIds
     * @return EloquentCollection<int, StockBalance>
     */
    public function expiring(?array $storeIds = null, ?int $days = null): EloquentCollection
    {
        return $this->expiringQuery($storeIds, $days)->get();
    }

    public function expiryWarningDays(): int
    {
        return max(1, (int) config('inventory.alerts.expiry_warning_days', 60));
    }

    /**
     * Ask for a level check once the current transaction commits (or right
     * away outside one). Called by the ledger on every central stock change.
     */
    public function queueEvaluation(string $itemId, string $storeId): void
    {
        // Not de-duplicated with a flag: the callback never runs when the
        // transaction rolls back, so a flag would stay set and silence the item
        // for the rest of a long-running worker. evaluate() is safe to repeat.
        DB::afterCommit(function () use ($itemId, $storeId): void {
            // The stock movement has already committed: an alert failure must
            // never surface as a failed issue or receipt.
            try {
                $this->evaluate($itemId, $storeId);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Raise, escalate or clear the alert for one item in one store.
     * Alerts once per drop: again only when it runs out, or after stock has
     * recovered above the reorder point and dropped again.
     */
    public function evaluate(string $itemId, string $storeId): void
    {
        if (! (bool) config('inventory.alerts.realtime_low_stock', true)) {
            return;
        }

        $levels = $this->levels($itemId, $storeId);
        $existing = StoreStockAlert::query()->where('store_id', $storeId)->where('inventory_item_id', $itemId)->first();

        if ($levels['reorder_point'] <= 0 || $levels['on_hand'] > $levels['reorder_point']) {
            $existing?->delete();

            return;
        }

        $level = $levels['on_hand'] <= 0 ? StoreStockAlert::LEVEL_OUT : StoreStockAlert::LEVEL_LOW;

        // Already told them (a partial top-up from "out" to "low" isn't news).
        if ($existing && ($existing->level === $level || $existing->level === StoreStockAlert::LEVEL_OUT)) {
            return;
        }

        StoreStockAlert::query()->updateOrCreate(
            ['store_id' => $storeId, 'inventory_item_id' => $itemId],
            ['level' => $level, 'quantity_at_alert' => $levels['on_hand'], 'alerted_at' => now()],
        );

        $store = Store::query()->find($storeId);
        $item = InventoryItem::query()->find($itemId);

        if (! $store || ! $item) {
            return;
        }

        $recipients = $this->recipientsFor($store);

        if ($recipients->isEmpty()) {
            return;
        }

        $suggested = $this->suggestedOrderQuantity($levels['on_hand'], $levels['reorder_point'], $levels['max_level']);

        $notification = Notification::make()
            ->title($level === StoreStockAlert::LEVEL_OUT
                ? __('Out of stock: :item in :store', ['item' => $item->name, 'store' => $store->name])
                : __('Low stock: :item in :store', ['item' => $item->name, 'store' => $store->name]))
            ->body(__(':on_hand left (reorder point :reorder). Suggested reorder: :suggested.', [
                'on_hand' => $levels['on_hand'],
                'reorder' => $levels['reorder_point'],
                'suggested' => $suggested,
            ]))
            ->icon('heroicon-o-exclamation-triangle');

        $level === StoreStockAlert::LEVEL_OUT ? $notification->danger() : $notification->warning();

        try {
            $notification->actions([
                Action::make('view')
                    ->label(__('Open stores dashboard'))
                    ->url(StoresDashboard::getUrl()),
            ]);
        } catch (\Throwable) {
            // No panel context (console/queue): send without the link.
        }

        $notification->sendToDatabase($recipients);
    }

    /**
     * A store's keepers, or super admins when it has none.
     *
     * @return EloquentCollection<int, User>
     */
    public function recipientsFor(Store $store): EloquentCollection
    {
        $keepers = $store->keepers()->get();

        if ($keepers->isNotEmpty()) {
            return $keepers;
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::SUPER_ADMIN->value))
            ->get();
    }

    protected function centralRows(string $itemId, string $storeId): Builder
    {
        return StockBalance::query()
            ->where('inventory_item_id', $itemId)
            ->where('store_id', $storeId)
            ->where('location_type', StockLocationType::Dispensary->value)
            ->whereNull('department_id')
            ->whereNull('stock_transfer_id');
    }
}
