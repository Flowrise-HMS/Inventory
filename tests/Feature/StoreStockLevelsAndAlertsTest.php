<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Classes\Services\StoreStockLevelService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Filament\Clusters\Inventory\Pages\StoresDashboard;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores\ExpiringStockTableWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores\StoreLowStockTableWidget;
use Modules\Inventory\Filament\Clusters\Inventory\Widgets\Stores\StoreStockStatsWidget;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\StoreStockAlert;
use Modules\Inventory\Notifications\InventoryReorderAlertNotification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreStockLevelsAndAlertsTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Store $main;

    private User $keeper;

    private InventoryItem $gloves;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);

        $this->branch = Branch::factory()->create();
        $this->main = Store::mainForBranch($this->branch->id);
        $this->keeper = User::factory()->create();
        $this->main->keepers()->attach($this->keeper->id);
        $this->gloves = InventoryItem::factory()->create(['name' => 'Examination Gloves']);
    }

    private function receive(int $qty, ?string $lot = null, ?string $expiry = null, ?Store $store = null): void
    {
        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $this->gloves->id,
            branchId: $this->branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: $qty,
            transactionType: TransactionType::Receive,
            lotNumber: $lot,
            expiryDate: $expiry,
            storeId: ($store ?? $this->main)->id,
        );
    }

    private function take(int $qty): void
    {
        app(StockLedgerService::class)->lockAndDecrement(
            itemId: $this->gloves->id,
            branchId: $this->branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: $qty,
            transactionType: TransactionType::Issue,
            storeId: $this->main->id,
        );
    }

    private function alertsFor(User $user): int
    {
        return $user->notifications()->count();
    }

    public function test_levels_apply_to_every_lot_and_drive_the_suggested_order(): void
    {
        $this->receive(4, 'LOT-A', now()->addYear()->toDateString());
        $this->receive(3, 'LOT-B', now()->addYears(2)->toDateString());

        $service = app(StoreStockLevelService::class);
        $service->setLevels($this->gloves->id, $this->main->id, 10, 40);

        $this->assertSame(2, StockBalance::query()->where('store_id', $this->main->id)->where('reorder_point', 10)->where('max_level', 40)->count());
        $this->assertSame(['on_hand' => 7, 'reorder_point' => 10, 'max_level' => 40], $service->levels($this->gloves->id, $this->main->id));

        $row = $service->lowStock([$this->main->id])->sole();
        $this->assertSame(33, $row['quantity_to_order'], 'Tops up to the max level.');
        $this->assertSame(3, $service->suggestedOrderQuantity(7, 10, null), 'Without a max level, back up to the reorder point.');

        // Lowering the reorder point must not be overridden by an older value on another lot.
        $service->setLevels($this->gloves->id, $this->main->id, 5, null);
        $this->assertSame(5, $service->levels($this->gloves->id, $this->main->id)['reorder_point']);
        $this->assertTrue($service->lowStock([$this->main->id])->isEmpty());
    }

    public function test_max_level_below_reorder_point_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        app(StoreStockLevelService::class)->setLevels($this->gloves->id, $this->main->id, 10, 5);
    }

    public function test_keeper_is_alerted_once_per_drop_and_again_when_it_runs_out(): void
    {
        $this->receive(20);
        app(StoreStockLevelService::class)->setLevels($this->gloves->id, $this->main->id, 5, 30);
        $this->assertSame(0, $this->alertsFor($this->keeper));

        $this->take(15); // 5 left: at the reorder point.
        $this->assertSame(1, $this->alertsFor($this->keeper));
        $this->assertSame(StoreStockAlert::LEVEL_LOW, StoreStockAlert::query()->sole()->level);

        $this->take(2); // Still low: no repeat.
        $this->assertSame(1, $this->alertsFor($this->keeper));

        $this->take(3); // Out.
        $this->assertSame(2, $this->alertsFor($this->keeper));
        $this->assertSame(StoreStockAlert::LEVEL_OUT, StoreStockAlert::query()->sole()->level);
        $titles = $this->keeper->notifications()->get()->pluck('data.title')->implode(' | ');
        $this->assertStringContainsString('Out of stock: Examination Gloves in Main Store', $titles);

        $this->receive(2); // Partial top-up from out to low: not news.
        $this->assertSame(2, $this->alertsFor($this->keeper));

        $this->receive(25); // Recovered: alert cleared.
        $this->assertSame(0, StoreStockAlert::query()->count());

        $this->take(24); // Drops again: alerts again.
        $this->assertSame(3, $this->alertsFor($this->keeper));
    }

    public function test_a_rolled_back_movement_does_not_silence_later_alerts(): void
    {
        $this->receive(20);
        app(StoreStockLevelService::class)->setLevels($this->gloves->id, $this->main->id, 5, null);

        try {
            DB::transaction(function (): void {
                $this->take(16);

                throw new \RuntimeException('Something else in the same transaction failed.');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $this->alertsFor($this->keeper), 'Nothing really moved.');

        $this->take(16);
        $this->assertSame(1, $this->alertsFor($this->keeper));
    }

    public function test_items_without_a_reorder_point_or_with_alerts_disabled_stay_quiet(): void
    {
        $this->receive(5);
        $this->take(5);
        $this->assertSame(0, $this->alertsFor($this->keeper), 'No reorder point, no alert.');

        config()->set('inventory.alerts.realtime_low_stock', false);
        $this->receive(10);
        app(StoreStockLevelService::class)->setLevels($this->gloves->id, $this->main->id, 5, null);
        $this->take(8);

        $this->assertSame(0, $this->alertsFor($this->keeper));
    }

    public function test_stores_without_keepers_alert_super_admins(): void
    {
        $linen = Store::factory()->create(['branch_id' => $this->branch->id, 'code' => 'LIN']);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->receive(3, store: $linen);
        app(StoreStockLevelService::class)->setLevels($this->gloves->id, $linen->id, 5, null);

        $this->assertSame(1, $this->alertsFor($admin));
        $this->assertSame(0, $this->alertsFor($this->keeper), 'The main store keeper does not hear about the linen store.');
    }

    public function test_digest_goes_to_each_stores_keepers_with_expiring_lots(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $linen = Store::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Linen Store', 'code' => 'LIN']);
        $soap = InventoryItem::factory()->create(['name' => 'Liquid Soap']);

        // Main store: low gloves plus a lot expiring next week.
        $this->receive(2, 'LOT-SOON', now()->addWeek()->toDateString());
        app(StoreStockLevelService::class)->setLevels($this->gloves->id, $this->main->id, 10, null);

        // Linen store (no keepers): low soap.
        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $soap->id,
            branchId: $this->branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 1,
            transactionType: TransactionType::Receive,
            storeId: $linen->id,
        );
        app(StoreStockLevelService::class)->setLevels($soap->id, $linen->id, 4, null);

        $this->artisan('inventory:check-stock-alerts')->assertExitCode(0);

        Notification::assertSentTo($this->keeper, InventoryReorderAlertNotification::class, function (InventoryReorderAlertNotification $n): bool {
            return count($n->suggestions) === 1
                && $n->suggestions[0]['inventory_item_id'] === $this->gloves->id
                && count($n->expiring) === 1
                && $n->expiring[0]['lot_number'] === 'LOT-SOON'
                && $n->expiring[0]['is_expired'] === false;
        });

        Notification::assertSentTo($admin, InventoryReorderAlertNotification::class, function (InventoryReorderAlertNotification $n) use ($soap): bool {
            return collect($n->suggestions)->pluck('inventory_item_id')->all() === [$soap->id]
                && $n->suggestions[0]['store_name'] === 'Linen Store';
        });

        Notification::assertSentToTimes($this->keeper, InventoryReorderAlertNotification::class, 1);
    }

    public function test_expiring_includes_expired_lots_but_not_far_future_ones(): void
    {
        $this->receive(2, 'OLD', now()->subDays(3)->toDateString());
        $this->receive(2, 'SOON', now()->addDays(10)->toDateString());
        $this->receive(2, 'LATER', now()->addYear()->toDateString());

        $lots = app(StoreStockLevelService::class)->expiring([$this->main->id], 30)->pluck('lot_number')->all();

        $this->assertSame(['OLD', 'SOON'], $lots);
    }

    public function test_stores_dashboard_and_widgets_render_for_the_keeper(): void
    {
        Gate::before(fn (): bool => true);
        Filament::setCurrentPanel(Filament::getDefaultPanel());

        $this->receive(1, 'SOON', now()->addDays(5)->toDateString());
        app(StoreStockLevelService::class)->setLevels($this->gloves->id, $this->main->id, 5, 20);

        $this->actingAs($this->keeper);

        Livewire::test(StoresDashboard::class)->assertOk();
        Livewire::test(StoreStockStatsWidget::class, ['pageFilters' => ['store_id' => $this->main->id]])
            ->assertOk()
            ->assertSee('Low stock');
        Livewire::test(StoreLowStockTableWidget::class, ['pageFilters' => ['store_id' => $this->main->id]])
            ->assertOk()
            ->assertSee('Examination Gloves')
            ->assertSee('19');
        Livewire::test(ExpiringStockTableWidget::class, ['pageFilters' => []])
            ->assertOk()
            ->assertSee('SOON');
    }
}
