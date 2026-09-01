<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\IssueToWardService;
use Modules\Inventory\Classes\Services\PurchaseOrderService;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Supplier;
use Tests\TestCase;

class StockLotFefoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    private function createDepartmentForBranch(Branch $branch): Department
    {
        $department = Department::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $department->locations()->attach($location->id, ['is_primary' => true]);

        return $department;
    }

    public function test_po_receive_creates_separate_lot_balances(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = InventoryItem::factory()->create();

        $po = app(PurchaseOrderService::class)->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_ordered' => 20],
            ],
        ]);

        app(PurchaseOrderService::class)->submit($po);

        app(PurchaseOrderService::class)->receive($po, [
            'items' => [
                [
                    'purchase_order_item_id' => $po->items->first()->id,
                    'quantity_received' => 10,
                    'lot_number' => 'LOT-A',
                    'expiry_date' => now()->addMonths(6)->toDateString(),
                ],
                [
                    'purchase_order_item_id' => $po->items->first()->id,
                    'quantity_received' => 10,
                    'lot_number' => 'LOT-B',
                    'expiry_date' => now()->addYear()->toDateString(),
                ],
            ],
        ]);

        $this->assertSame(2, StockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->where('location_type', StockLocationType::Dispensary)
            ->count());

        $this->assertSame(20, (int) StockBalance::dispensaryOnHand($item->id, $branch->id));
    }

    public function test_issue_to_ward_uses_fefo_and_preserves_lots(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();
        $ledger = app(StockLedgerService::class);

        $ledger->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 10,
            transactionType: TransactionType::Receive,
            reference: null,
            lotNumber: 'SOON',
            expiryDate: now()->addMonth()->toDateString(),
        );

        $ledger->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 10,
            transactionType: TransactionType::Receive,
            reference: null,
            lotNumber: 'LATER',
            expiryDate: now()->addYear()->toDateString(),
        );

        $requisition = app(RequisitionService::class)->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 12],
            ],
        ]);

        app(RequisitionService::class)->approve($requisition);
        app(IssueToWardService::class)->issue($requisition->items->first(), 12);

        $this->assertSame(0, (int) StockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('lot_number', 'SOON')
            ->where('location_type', StockLocationType::Dispensary)
            ->value('quantity_on_hand'));

        $this->assertSame(8, (int) StockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('lot_number', 'LATER')
            ->where('location_type', StockLocationType::Dispensary)
            ->value('quantity_on_hand'));

        $this->assertSame(10, (int) StockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('lot_number', 'SOON')
            ->where('location_type', StockLocationType::Ward)
            ->where('department_id', $department->id)
            ->value('quantity_on_hand'));

        $this->assertSame(2, (int) StockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('lot_number', 'LATER')
            ->where('location_type', StockLocationType::Ward)
            ->where('department_id', $department->id)
            ->value('quantity_on_hand'));
    }

    /**
     * FEFO sorts by earliest expiry, so an expired lot sorts first and would be
     * the first thing dispensed unless it is excluded outright.
     */
    public function test_expired_lots_are_never_dispensed(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();
        $ledger = app(StockLedgerService::class);

        $this->receiveLot($item->id, $branch->id, 10, 'EXPIRED', now()->subDay()->toDateString());
        $this->receiveLot($item->id, $branch->id, 10, 'VALID', now()->addYear()->toDateString());

        $allocations = $ledger->decrementFefo(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 6,
            transactionType: TransactionType::Issue,
        );

        $this->assertCount(1, $allocations);
        $this->assertSame('VALID', $allocations[0]['lot_number']);
        $this->assertSame(6, $allocations[0]['quantity']);

        $this->assertSame(10, $this->onHand($item->id, 'EXPIRED'), 'Expired lot must be left untouched.');
        $this->assertSame(4, $this->onHand($item->id, 'VALID'));
    }

    public function test_decrement_fails_closed_when_only_expired_stock_remains(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $this->receiveLot($item->id, $branch->id, 50, 'EXPIRED', now()->subMonth()->toDateString());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/expired/i');

        app(StockLedgerService::class)->decrementFefo(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 1,
            transactionType: TransactionType::Issue,
        );
    }

    public function test_lot_expiring_today_is_still_dispensable(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $this->receiveLot($item->id, $branch->id, 5, 'TODAY', now()->toDateString());

        $allocations = app(StockLedgerService::class)->decrementFefo(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 5,
            transactionType: TransactionType::Issue,
        );

        $this->assertCount(1, $allocations);
        $this->assertSame('TODAY', $allocations[0]['lot_number']);
    }

    public function test_lots_without_an_expiry_date_remain_dispensable(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $this->receiveLot($item->id, $branch->id, 7, 'NO-EXPIRY', null);

        $allocations = app(StockLedgerService::class)->decrementFefo(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 7,
            transactionType: TransactionType::Issue,
        );

        $this->assertCount(1, $allocations);
        $this->assertSame('NO-EXPIRY', $allocations[0]['lot_number']);
    }

    /**
     * Writing expired stock off is exactly an adjustment, so adjustments must
     * keep the access that issuing and shipping lose.
     */
    public function test_adjustments_can_still_write_off_expired_stock(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $this->receiveLot($item->id, $branch->id, 12, 'EXPIRED', now()->subWeek()->toDateString());

        $allocations = app(StockLedgerService::class)->decrementFefo(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 12,
            transactionType: TransactionType::Adjust,
            reference: null,
        );

        $this->assertCount(1, $allocations);
        $this->assertSame('EXPIRED', $allocations[0]['lot_number']);
        $this->assertSame(0, $this->onHand($item->id, 'EXPIRED'));
    }

    public function test_transfers_may_not_ship_expired_stock(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $this->receiveLot($item->id, $branch->id, 8, 'EXPIRED', now()->subDay()->toDateString());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/expired/i');

        app(StockLedgerService::class)->decrementFefo(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 1,
            transactionType: TransactionType::TransferShip,
            reference: null,
        );
    }

    private function receiveLot(string $itemId, string $branchId, int $qty, string $lot, ?string $expiry): void
    {
        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $itemId,
            branchId: $branchId,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: $qty,
            transactionType: TransactionType::Receive,
            reference: null,
            lotNumber: $lot,
            expiryDate: $expiry,
        );
    }

    private function onHand(string $itemId, string $lot): int
    {
        return (int) StockBalance::query()
            ->where('inventory_item_id', $itemId)
            ->where('lot_number', $lot)
            ->where('location_type', StockLocationType::Dispensary)
            ->value('quantity_on_hand');
    }
}
