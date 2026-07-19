<?php

namespace Modules\Inventory\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Contracts\InventoryLowStockProviderContract;
use Modules\Core\Contracts\PharmacyStockItemTableActionsContract;
use Modules\Core\Contracts\WardMedicationConsumptionContract;
use Modules\Core\Contracts\WardStockConsumptionContract;
use Modules\Core\Settings\NotificationSettings;
use Modules\Inventory\Classes\Support\InventoryLowStockProvider;
use Modules\Inventory\Classes\Support\InventoryPharmacyStockItemTableActions;
use Modules\Inventory\Classes\Support\InventoryWardMedicationConsumption;
use Modules\Inventory\Classes\Support\InventoryWardStockConsumption;
use Modules\Inventory\Console\CheckStockAlertsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Inventory';

    /**
     * Lowercase name of the module.
     */
    protected string $nameLower = 'inventory';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        CheckStockAlertsCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(
            PharmacyStockItemTableActionsContract::class,
            InventoryPharmacyStockItemTableActions::class,
        );
        $this->app->bind(InventoryLowStockProviderContract::class, InventoryLowStockProvider::class);
        $this->app->bind(WardStockConsumptionContract::class, InventoryWardStockConsumption::class);
        $this->app->bind(WardMedicationConsumptionContract::class, InventoryWardMedicationConsumption::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('inventory:check-stock-alerts')
                ->when(function () {
                    try {
                        $settings = app(NotificationSettings::class);

                        return $settings->inventory_reorder_alerts_enabled && $settings->inventory_reorder_alerts_frequency === 'daily';
                    } catch (\Throwable) {
                        return false;
                    }
                })
                ->daily();

            $schedule->command('inventory:check-stock-alerts')
                ->when(function () {
                    try {
                        $settings = app(NotificationSettings::class);

                        return $settings->inventory_reorder_alerts_enabled && $settings->inventory_reorder_alerts_frequency === 'weekly';
                    } catch (\Throwable) {
                        return false;
                    }
                })
                ->weekly();

            $schedule->command('inventory:check-stock-alerts')
                ->when(function () {
                    try {
                        $settings = app(NotificationSettings::class);

                        return $settings->inventory_reorder_alerts_enabled && $settings->inventory_reorder_alerts_frequency === 'monthly';
                    } catch (\Throwable) {
                        return false;
                    }
                })
                ->monthly();
        });
    }
}
