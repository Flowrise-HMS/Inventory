<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\IssueToWardService;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockConsumptionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Tests\TestCase;

class StockConsumptionServiceTest extends TestCase
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

    public function test_consumes_stock_from_ward_balance(): void
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
                ['inventory_item_id' => $item->id, 'quantity_requested' => 20],
            ],
        ]);

        app(RequisitionService::class)->approve($requisition);
        app(IssueToWardService::class)->issue($requisition->items->first(), 20);

        app(StockConsumptionService::class)->consumeFromWard(
            itemId: $item->id,
            branchId: $branch->id,
            departmentId: $department->id,
            qty: 5,
        );

        $this->assertSame(15, (int) StockBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->where('location_type', StockLocationType::Ward)
            ->where('department_id', $department->id)
            ->value('quantity_on_hand'));
    }
}
