<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Unit;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Tests\TestCase;

class StockLedgerServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_lock_and_increment_creates_balance_if_not_exists(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $unit = Unit::factory()->create();
        $item = InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $service = app(StockLedgerService::class);

        $balance = $service->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 50,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $this->assertEquals(50, $balance->quantity_on_hand);
        $this->assertEquals($item->id, $balance->inventory_item_id);
    }

    public function test_lock_and_decrement_reduces_balance(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $unit = Unit::factory()->create();
        $item = InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $service = app(StockLedgerService::class);

        $service->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 100,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $balance = $service->lockAndDecrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 30,
            transactionType: TransactionType::Issue,
            reference: null,
        );

        $this->assertEquals(70, $balance->quantity_on_hand);
    }

    public function test_lock_and_decrement_with_insufficient_stock_throws_exception(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $unit = Unit::factory()->create();
        $item = InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $service = app(StockLedgerService::class);

        $service->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 10,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock on hand.');

        $service->lockAndDecrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 20,
            transactionType: TransactionType::Issue,
            reference: null,
        );
    }

    public function test_add_opening_stock_creates_dispensary_balance_with_quantity_and_reorder_point(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $unit = Unit::factory()->create();
        $item = InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $service = app(StockLedgerService::class);

        $balance = $service->addOpeningStock(
            itemId: $item->id,
            branchId: $branch->id,
            qty: 25,
            reorderPoint: 5,
        );

        $this->assertEquals(25, $balance->quantity_on_hand);
        $this->assertEquals(5, $balance->reorder_point);
        $this->assertEquals(StockLocationType::Dispensary, $balance->location_type);
    }

    public function test_add_opening_stock_accumulates_on_existing_balance(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $unit = Unit::factory()->create();
        $item = InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $service = app(StockLedgerService::class);

        $service->addOpeningStock(itemId: $item->id, branchId: $branch->id, qty: 10);
        $balance = $service->addOpeningStock(itemId: $item->id, branchId: $branch->id, qty: 15);

        $this->assertEquals(25, $balance->quantity_on_hand);
    }

    public function test_add_opening_stock_with_zero_quantity_only_sets_reorder_point(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $unit = Unit::factory()->create();
        $item = InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $service = app(StockLedgerService::class);

        $balance = $service->addOpeningStock(itemId: $item->id, branchId: $branch->id, qty: 0, reorderPoint: 10);

        $this->assertEquals(0, $balance->quantity_on_hand);
        $this->assertEquals(10, $balance->reorder_point);
    }
}
