<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Unit;
use Modules\Inventory\Classes\Services\InterBranchTransferService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Tests\TestCase;

class InterBranchTransferServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_can_create_transfer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $fromBranch = Branch::factory()->create();
        $toBranch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $service = app(InterBranchTransferService::class);

        $transfer = $service->create([
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 30],
            ],
        ]);

        $this->assertNotNull($transfer->transfer_number);
        $this->assertEquals(StockTransferStatus::Draft, $transfer->status);
        $this->assertCount(1, $transfer->items);
        $this->assertEquals(30, $transfer->items->first()->quantity_requested);
    }

    public function test_can_ship_transfer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $fromBranch = Branch::factory()->create();
        $toBranch = Branch::factory()->create();
        $unit = Unit::factory()->create();
        $item = InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $service = app(InterBranchTransferService::class);

        $transfer = $service->create([
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 30],
            ],
        ]);

        // Seed stock in fromBranch dispensary (normal dispensary balance)
        $ledger = app(StockLedgerService::class);
        $ledger->lockAndIncrement(
            itemId: $item->id,
            branchId: $fromBranch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 50,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $service->ship($transfer, [$transfer->items->first()->id => 30]);
        $transfer->refresh();

        $this->assertEquals(StockTransferStatus::Shipped, $transfer->status);
        $this->assertEquals(30, $transfer->items->first()->quantity_shipped);
    }

    public function test_can_receive_transfer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $fromBranch = Branch::factory()->create();
        $toBranch = Branch::factory()->create();
        $unit = Unit::factory()->create();
        $item = InventoryItem::factory()->create(['unit_id' => $unit->id]);

        $service = app(InterBranchTransferService::class);

        $transfer = $service->create([
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 30],
            ],
        ]);

        // Seed stock in fromBranch dispensary (normal dispensary balance)
        $ledger = app(StockLedgerService::class);
        $ledger->lockAndIncrement(
            itemId: $item->id,
            branchId: $fromBranch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 50,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $service->ship($transfer, [$transfer->items->first()->id => 30]);

        $service->receive($transfer, [$transfer->items->first()->id => 30]);
        $transfer->refresh();

        $this->assertEquals(StockTransferStatus::Received, $transfer->status);
        $this->assertEquals(30, $transfer->items->first()->quantity_received);
    }
}
