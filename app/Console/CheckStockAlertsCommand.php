<?php

namespace Modules\Inventory\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification as FacadeNotification;
use Modules\Inventory\Classes\Services\StoreStockLevelService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Notifications\InventoryReorderAlertNotification;

class CheckStockAlertsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:check-stock-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send each store\'s keepers (super admins if a store has none) a digest of low-stock items and expired or expiring lots';

    /**
     * Execute the console command.
     */
    public function handle(StoreStockLevelService $levels): int
    {
        $this->info('Scanning store stock levels and expiry dates...');

        $lowStock = $levels->lowStock()->groupBy('store_id');
        $expiring = $levels->expiring()->groupBy('store_id');
        $storeIds = $lowStock->keys()->merge($expiring->keys())->unique()->values();

        if ($storeIds->isEmpty()) {
            $this->info('All store items are well-stocked and nothing is expiring. No alerts triggered.');

            return self::SUCCESS;
        }

        /** @var array<int, array{user: User, suggestions: list<array<string, mixed>>, expiring: list<array<string, mixed>>}> $digests */
        $digests = [];
        $unrouted = 0;

        foreach (Store::query()->whereIn('id', $storeIds)->get() as $store) {
            $recipients = $levels->recipientsFor($store);
            $storeLow = $lowStock->get($store->id, collect())->map(fn (array $row): array => collect($row)->except(['key', 'is_out'])->all())->values()->all();
            $storeExpiring = $expiring->get($store->id, collect())->map(fn (StockBalance $lot): array => [
                'item_name' => (string) $lot->inventoryItem?->name,
                'store_name' => $store->name,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString() ?? '',
                'quantity_on_hand' => (int) $lot->quantity_on_hand,
                'is_expired' => $lot->expiry_date !== null && $lot->expiry_date->isPast() && ! $lot->expiry_date->isToday(),
            ])->values()->all();

            $this->line(sprintf('%s: %d low-stock item(s), %d expiring lot(s).', $store->name, count($storeLow), count($storeExpiring)));

            if ($recipients->isEmpty()) {
                $unrouted++;
                $this->warn(sprintf('No keepers or super admins to notify for %s.', $store->name));

                continue;
            }

            // One notification per person, covering every store they look after.
            foreach ($recipients as $user) {
                $digests[$user->getKey()] ??= ['user' => $user, 'suggestions' => [], 'expiring' => []];
                array_push($digests[$user->getKey()]['suggestions'], ...$storeLow);
                array_push($digests[$user->getKey()]['expiring'], ...$storeExpiring);
            }
        }

        if ($digests === []) {
            $this->error('No store keepers or super_admin users found to receive the alert.');

            return self::FAILURE;
        }

        foreach ($digests as $digest) {
            FacadeNotification::send($digest['user'], new InventoryReorderAlertNotification($digest['suggestions'], $digest['expiring']));
        }

        $this->info(sprintf('Sent stores digest to %d user(s).', count($digests)));

        if ($unrouted > 0) {
            $this->warn(sprintf('%d store(s) had nobody to notify.', $unrouted));
        }

        return self::SUCCESS;
    }
}
