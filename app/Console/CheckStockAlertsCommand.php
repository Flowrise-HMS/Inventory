<?php

namespace Modules\Inventory\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification as FacadeNotification;
use App\Models\User;
use Modules\Inventory\Classes\Services\AutoReorderService;
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
    protected $description = 'Scan dispensary inventory balances and trigger alerts for items below reorder points';

    /**
     * Execute the console command.
     */
    public function handle(AutoReorderService $reorderService): int
    {
        $this->info('Scanning dispensary stock balances...');

        $suggestions = $reorderService->suggestions();

        if (count($suggestions) === 0) {
            $this->info('All dispensary items are well-stocked. No alerts triggered.');

            return 0;
        }

        $this->warn(sprintf('Found %d item(s) below their reorder points!', count($suggestions)));

        // Fetch all active super admins to notify, using database relational queries to bypass permission cache in tests
        $recipients = User::whereHas('roles', function ($query) {
            $query->where('name', 'super_admin');
        })->get();

        if ($recipients->isEmpty()) {
            $this->error('No active super_admin users found to receive the alert.');

            return 1;
        }

        $this->info(sprintf('Sending low-stock alert notifications to %d super_admin user(s)...', $recipients->count()));

        FacadeNotification::send($recipients, new InventoryReorderAlertNotification($suggestions));

        $this->info('Low-stock alert notifications dispatched successfully.');

        return 0;
    }
}
