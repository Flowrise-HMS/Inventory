<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Settings\NotificationSettings;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Notifications\InventoryReorderAlertNotification;
use Tests\TestCase;

class CheckStockAlertsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_inventory_check_alerts_command_registers_in_scheduler(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $reorderEvents = $events->filter(function ($event) {
            return str_contains($event->command, 'inventory:check-stock-alerts');
        });

        $this->assertGreaterThanOrEqual(3, $reorderEvents->count(), 'At least 3 reorder alert schedule events should be registered (daily, weekly, monthly).');
    }

    public function test_reorder_alerts_schedule_is_inactive_when_disabled(): void
    {
        $settings = app(NotificationSettings::class);
        $settings->inventory_reorder_alerts_enabled = false;
        $settings->inventory_reorder_alerts_frequency = 'daily';
        $settings->save();

        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $reorderEvents = $events->filter(function ($event) {
            return str_contains($event->command, 'inventory:check-stock-alerts');
        });

        foreach ($reorderEvents as $event) {
            $this->assertFalse($event->filtersPass(app()), 'Reorder schedule should not pass filters when disabled.');
        }
    }

    public function test_reorder_alerts_schedule_is_active_for_daily_when_configured(): void
    {
        $settings = app(NotificationSettings::class);
        $settings->inventory_reorder_alerts_enabled = true;
        $settings->inventory_reorder_alerts_frequency = 'daily';
        $settings->save();

        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        // Daily event
        $dailyEvent = $events->first(function ($event) {
            return str_contains($event->command, 'inventory:check-stock-alerts') && $event->expression === '0 0 * * *';
        });

        $this->assertNotNull($dailyEvent, 'Daily reorder event should be registered.');
        $this->assertTrue($dailyEvent->filtersPass(app()), 'Daily reorder should pass filters when configured to daily.');

        // Weekly event should fail the filters
        $weeklyEvent = $events->first(function ($event) {
            return str_contains($event->command, 'inventory:check-stock-alerts') && $event->expression === '0 0 * * 0';
        });

        $this->assertNotNull($weeklyEvent, 'Weekly reorder event should be registered.');
        $this->assertFalse($weeklyEvent->filtersPass(app()), 'Weekly reorder should not pass filters when configured to daily.');
    }

    public function test_reorder_alerts_command_sends_notification_on_low_stock(): void
    {
        Notification::fake();

        // 1. Create a branch and a super admin user to receive alerts
        $branch = Branch::factory()->create();
        $role = Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole($role);

        // 2. Create an item and opening stock BELOW reorder point
        $lowItem = InventoryItem::factory()->create(['name' => 'Needles']);
        app(StockLedgerService::class)->addOpeningStock(
            itemId: $lowItem->id,
            branchId: $branch->id,
            qty: 2,
            reorderPoint: 10,
        );

        // 3. Run the command and assert exit code is 0
        $this->artisan('inventory:check-stock-alerts')
            ->assertExitCode(0);

        // 4. Assert Notification was sent
        Notification::assertSentTo(
            $admin,
            InventoryReorderAlertNotification::class,
            function ($notification) use ($lowItem) {
                return count($notification->suggestions) === 1 &&
                    $notification->suggestions[0]['inventory_item_id'] === $lowItem->id;
            }
        );
    }
}
