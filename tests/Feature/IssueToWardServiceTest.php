<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\IssueToWardService;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Tests\TestCase;

class IssueToWardServiceTest extends TestCase
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

    public function test_issues_stock_from_dispensary_to_ward(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 50,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $requisition = app(RequisitionService::class)->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 15],
            ],
        ]);

        app(RequisitionService::class)->approve($requisition);
        $line = $requisition->items->first();

        app(IssueToWardService::class)->issue($line, 15);

        $this->assertSame(35, StockBalance::dispensaryOnHand($item->id, $branch->id));
        $this->assertSame(15, (int) StockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->where('location_type', StockLocationType::Ward)
            ->where('department_id', $department->id)
            ->value('quantity_on_hand'));
    }

    public function test_throws_when_dispensary_stock_insufficient(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        $requisition = app(RequisitionService::class)->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 5],
            ],
        ]);

        app(RequisitionService::class)->approve($requisition);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock on hand.');

        app(IssueToWardService::class)->issue($requisition->items->first(), 5);
    }
}
