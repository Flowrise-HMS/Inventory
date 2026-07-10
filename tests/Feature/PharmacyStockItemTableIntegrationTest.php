<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Contracts\PharmacyStockItemTableActionsContract;
use Modules\Inventory\Classes\Support\InventoryPharmacyStockItemTableActions;
use Tests\TestCase;

class PharmacyStockItemTableIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireModule('Inventory');
        $this->migrateModules(['Core', 'Inventory', 'Pharmacy']);
    }

    public function test_inventory_binds_pharmacy_stock_item_table_actions(): void
    {
        $this->assertInstanceOf(
            InventoryPharmacyStockItemTableActions::class,
            app(PharmacyStockItemTableActionsContract::class),
        );
    }

    public function test_central_store_action_registered_when_linked_item_exists(): void
    {
        $actions = app(PharmacyStockItemTableActionsContract::class)->recordActions();

        $this->assertCount(1, $actions);
        $this->assertSame('request_central_store', $actions[0]->getName());
    }
}
