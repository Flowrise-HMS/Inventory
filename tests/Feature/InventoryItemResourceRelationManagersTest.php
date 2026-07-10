<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\InventoryItemResource;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\RelationManagers\InventoryTransactionsRelationManager;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\RelationManagers\StockBalancesRelationManager;
use Tests\TestCase;

class InventoryItemResourceRelationManagersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_resource_registers_stock_balance_and_transaction_relation_managers(): void
    {
        $relations = InventoryItemResource::getRelations();

        $this->assertContains(StockBalancesRelationManager::class, $relations);
        $this->assertContains(InventoryTransactionsRelationManager::class, $relations);
    }

    public function test_stock_balances_relation_manager_uses_stock_balances_relationship(): void
    {
        $reflection = new \ReflectionClass(StockBalancesRelationManager::class);
        $relationshipProperty = $reflection->getProperty('relationship');

        $this->assertSame('stockBalances', $relationshipProperty->getDefaultValue());
    }

    public function test_inventory_transactions_relation_manager_uses_transactions_relationship(): void
    {
        $reflection = new \ReflectionClass(InventoryTransactionsRelationManager::class);
        $relationshipProperty = $reflection->getProperty('relationship');

        $this->assertSame('transactions', $relationshipProperty->getDefaultValue());
    }
}
