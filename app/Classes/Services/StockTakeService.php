<?php

namespace Modules\Inventory\Classes\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\StockTakeStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockTake;
use Modules\Inventory\Models\StockTakeLine;
use Modules\Inventory\Models\Store;

/**
 * Store stock-takes.
 *
 * Starting a count snapshots every central lot in the store. Stock keeps
 * moving while people count, so approval posts each line's variance
 * (counted − snapshot) as an adjustment rather than overwriting the current
 * balance: issues made during the count are not lost.
 */
class StockTakeService
{
    public function __construct(
        protected DocumentNumberingService $numbering,
        protected StockAdjustmentService $adjustments,
    ) {}

    public function start(Store $store, bool $isBlind = false, ?string $notes = null, ?User $user = null): StockTake
    {
        $user ??= Auth::user();

        if (! $store->is_active) {
            throw new \RuntimeException('Stock-takes can only be started for active stores.');
        }

        return DB::transaction(function () use ($store, $isBlind, $notes, $user): StockTake {
            // Serialise starts per store so two people can't open parallel counts.
            Store::query()->whereKey($store->getKey())->lockForUpdate()->first();

            if (StockTake::query()->where('store_id', $store->getKey())->open()->exists()) {
                throw new \RuntimeException(sprintf('%s already has a stock-take in progress. Finish or cancel it first.', $store->name));
            }

            $stockTake = StockTake::query()->create([
                'reference' => $this->numbering->generate('STK', (string) $store->branch_id),
                'store_id' => $store->getKey(),
                'branch_id' => $store->branch_id,
                'status' => StockTakeStatus::InProgress->value,
                'is_blind' => $isBlind,
                'notes' => filled($notes) ? trim((string) $notes) : null,
                'started_by' => $user?->getKey(),
                'started_at' => now(),
            ]);

            $now = now();
            $rows = StockBalance::query()
                ->join('inventory_items', 'inventory_items.id', '=', 'stock_balances.inventory_item_id')
                ->where('stock_balances.store_id', $store->getKey())
                ->where('stock_balances.location_type', StockLocationType::Dispensary->value)
                ->whereNull('stock_balances.department_id')
                ->whereNull('stock_balances.stock_transfer_id')
                ->where(fn ($q) => $q->where('stock_balances.quantity_on_hand', '!=', 0)->orWhere('inventory_items.is_active', true))
                ->orderBy('inventory_items.name')
                ->orderBy('stock_balances.expiry_date')
                ->lockForUpdate()
                ->get(['stock_balances.inventory_item_id', 'stock_balances.lot_number', 'stock_balances.expiry_date', 'stock_balances.quantity_on_hand']);

            $lines = $rows->map(fn (StockBalance $row): array => [
                'id' => (string) Str::uuid(),
                'stock_take_id' => $stockTake->getKey(),
                'inventory_item_id' => $row->inventory_item_id,
                'lot_number' => $row->lot_number,
                'expiry_date' => $row->expiry_date?->toDateString(),
                'system_quantity' => (int) $row->quantity_on_hand,
                'is_found_item' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($lines, 500) as $chunk) {
                StockTakeLine::query()->insert($chunk);
            }

            return $stockTake;
        });
    }

    public function recordCount(StockTakeLine $line, ?int $counted, ?string $reason = null, ?User $user = null): StockTakeLine
    {
        $user ??= Auth::user();
        $this->assertCounting($line->stockTake);

        if ($counted !== null && $counted < 0) {
            throw new \RuntimeException('A count cannot be negative.');
        }

        $line->update([
            'counted_quantity' => $counted,
            'variance_reason' => filled($reason) ? trim((string) $reason) : $line->variance_reason,
            'counted_by' => $counted === null ? null : $user?->getKey(),
            'counted_at' => $counted === null ? null : now(),
        ]);

        return $line;
    }

    public function recordReason(StockTakeLine $line, ?string $reason): StockTakeLine
    {
        if (! in_array($line->stockTake->status, [StockTakeStatus::InProgress, StockTakeStatus::Submitted], true)) {
            throw new \RuntimeException('This stock-take is closed.');
        }

        $line->update(['variance_reason' => filled($reason) ? trim((string) $reason) : null]);

        return $line;
    }

    /**
     * Stock found on the shelf that the system has no record of.
     */
    public function addFoundItem(
        StockTake $stockTake,
        string $itemId,
        ?string $lotNumber,
        ?string $expiryDate,
        int $counted,
        ?string $reason = null,
        ?User $user = null,
    ): StockTakeLine {
        $user ??= Auth::user();
        $this->assertCounting($stockTake);

        if ($counted < 1) {
            throw new \RuntimeException('Enter how many were found.');
        }

        InventoryItem::query()->findOrFail($itemId);
        $lotNumber = filled($lotNumber) ? trim((string) $lotNumber) : null;
        $expiryDate = filled($expiryDate) ? \Illuminate\Support\Carbon::parse($expiryDate)->toDateString() : null;

        $exists = $stockTake->lines()
            ->where('inventory_item_id', $itemId)
            ->when($lotNumber === null, fn ($q) => $q->whereNull('lot_number'), fn ($q) => $q->where('lot_number', $lotNumber))
            ->when($expiryDate === null, fn ($q) => $q->whereNull('expiry_date'), fn ($q) => $q->whereDate('expiry_date', $expiryDate))
            ->exists();

        if ($exists) {
            throw new \RuntimeException('That item and lot is already on the count sheet: enter the count on its line.');
        }

        return $stockTake->lines()->create([
            'inventory_item_id' => $itemId,
            'lot_number' => $lotNumber,
            'expiry_date' => $expiryDate,
            'system_quantity' => 0,
            'is_found_item' => true,
            'counted_quantity' => $counted,
            'variance_reason' => filled($reason) ? trim((string) $reason) : null,
            'counted_by' => $user?->getKey(),
            'counted_at' => now(),
        ]);
    }

    public function submit(StockTake $stockTake, ?User $user = null): void
    {
        $user ??= Auth::user();
        $this->assertCounting($stockTake);

        $uncounted = $stockTake->lines()->whereNull('counted_quantity')->count();

        if ($uncounted > 0) {
            throw new \RuntimeException(sprintf('%d line(s) have not been counted. Enter 0 for anything not on the shelf.', $uncounted));
        }

        // In a blind count the counters can't see differences, so the
        // approver explains them before approving instead.
        if (! $stockTake->is_blind && ($unexplained = $this->unexplainedCount($stockTake)) > 0) {
            throw new \RuntimeException(sprintf('%d line(s) differ from the system quantity without a reason.', $unexplained));
        }

        $stockTake->update([
            'status' => StockTakeStatus::Submitted->value,
            'submitted_by' => $user?->getKey(),
            'submitted_at' => now(),
            'status_reason' => null,
        ]);
    }

    public function returnForRecount(StockTake $stockTake, string $reason): void
    {
        if ($stockTake->status !== StockTakeStatus::Submitted) {
            throw new \RuntimeException('Only stock-takes awaiting approval can be sent back for recount.');
        }

        $stockTake->update([
            'status' => StockTakeStatus::InProgress->value,
            'status_reason' => trim($reason),
            'submitted_by' => null,
            'submitted_at' => null,
        ]);
    }

    /**
     * Post every variance as an adjustment, all or nothing.
     */
    public function approve(StockTake $stockTake, ?User $user = null): void
    {
        $user ??= Auth::user();

        if ($stockTake->status !== StockTakeStatus::Submitted) {
            throw new \RuntimeException('Only submitted stock-takes can be approved.');
        }

        if ($user !== null
            && (int) $stockTake->submitted_by === (int) $user->getKey()
            && ! $user->can('manage_all_stores')) {
            throw new \RuntimeException('Someone other than the person who submitted the count must approve it.');
        }

        DB::transaction(function () use ($stockTake, $user): void {
            $fresh = StockTake::query()->lockForUpdate()->findOrFail($stockTake->getKey());

            if ($fresh->status !== StockTakeStatus::Submitted) {
                throw new \RuntimeException('This stock-take was already processed.');
            }

            if (($unexplained = $this->unexplainedCount($fresh)) > 0) {
                throw new \RuntimeException(sprintf('Add a reason for the %d line(s) that differ before approving.', $unexplained));
            }

            $lines = $fresh->lines()
                ->with('inventoryItem')
                ->whereColumn('counted_quantity', '!=', 'system_quantity')
                ->get();

            foreach ($lines as $line) {
                try {
                    $transaction = $this->adjustments->adjustStoreLot(
                        itemId: $line->inventory_item_id,
                        storeId: $fresh->store_id,
                        lotNumber: $line->lot_number,
                        expiryDate: $line->expiry_date?->toDateString(),
                        delta: (int) $line->variance(),
                        reference: $line,
                    );
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException(sprintf(
                        'Cannot post the variance for %s%s: %s Stock may have moved since the count; send it back for recount.',
                        $line->inventoryItem?->name ?? 'an item',
                        $line->lot_number ? " (lot {$line->lot_number})" : '',
                        $e->getMessage(),
                    ), previous: $e);
                }

                $line->update(['adjustment_transaction_id' => $transaction->getKey()]);
            }

            $fresh->update([
                'status' => StockTakeStatus::Approved->value,
                'approved_by' => $user?->getKey(),
                'approved_at' => now(),
            ]);
        });

        $stockTake->refresh();
    }

    public function cancel(StockTake $stockTake, string $reason, ?User $user = null): void
    {
        $user ??= Auth::user();

        if (! $stockTake->status->isOpen()) {
            throw new \RuntimeException('Only open stock-takes can be cancelled.');
        }

        $stockTake->update([
            'status' => StockTakeStatus::Cancelled->value,
            'cancelled_by' => $user?->getKey(),
            'cancelled_at' => now(),
            'status_reason' => trim($reason),
        ]);
    }

    /**
     * @return array{lines: int, counted: int, with_variance: int, units_over: int, units_short: int}
     */
    public function summary(StockTake $stockTake): array
    {
        $row = $stockTake->lines()
            ->toBase()
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NOT NULL THEN 1 ELSE 0 END) as counted')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NOT NULL AND counted_quantity != system_quantity THEN 1 ELSE 0 END) as with_variance')
            ->selectRaw('SUM(CASE WHEN counted_quantity > system_quantity THEN counted_quantity - system_quantity ELSE 0 END) as units_over')
            ->selectRaw('SUM(CASE WHEN counted_quantity < system_quantity THEN system_quantity - counted_quantity ELSE 0 END) as units_short')
            ->first();

        return [
            'lines' => (int) ($row->lines_count ?? 0),
            'counted' => (int) ($row->counted ?? 0),
            'with_variance' => (int) ($row->with_variance ?? 0),
            'units_over' => (int) ($row->units_over ?? 0),
            'units_short' => (int) ($row->units_short ?? 0),
        ];
    }

    protected function unexplainedCount(StockTake $stockTake): int
    {
        return $stockTake->lines()
            ->whereColumn('counted_quantity', '!=', 'system_quantity')
            ->where(fn ($q) => $q->whereNull('variance_reason')->orWhere('variance_reason', ''))
            ->count();
    }

    protected function assertCounting(StockTake $stockTake): void
    {
        if ($stockTake->status !== StockTakeStatus::InProgress) {
            throw new \RuntimeException('Counts can only be changed while the stock-take is being counted.');
        }
    }
}
