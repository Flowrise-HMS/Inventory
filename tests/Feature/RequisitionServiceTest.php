<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Tests\TestCase;

class RequisitionServiceTest extends TestCase
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

    public function test_can_create_requisition_with_items(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        $service = app(RequisitionService::class);

        $requisition = $service->create([
            'branch_id' => $branch->id,
            'requestor_id' => $user->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 20],
            ],
        ]);

        $this->assertNotNull($requisition->requisition_number);
        $this->assertEquals('pending', $requisition->status->value);
        $this->assertCount(1, $requisition->items);
        $this->assertEquals(20, $requisition->items->first()->quantity_requested);
    }

    public function test_approve_sets_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        $service = app(RequisitionService::class);

        $requisition = $service->create([
            'branch_id' => $branch->id,
            'requestor_id' => $user->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 20],
            ],
        ]);

        $service->approve($requisition);
        $requisition->refresh();

        $this->assertEquals(RequisitionStatus::Approved, $requisition->status);
        $this->assertEquals($user->id, $requisition->approved_by);
    }

    public function test_decline_sets_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        $service = app(RequisitionService::class);

        $requisition = $service->create([
            'branch_id' => $branch->id,
            'requestor_id' => $user->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 20],
            ],
        ]);

        $service->decline($requisition, 'Not needed at this time');
        $requisition->refresh();

        $this->assertEquals(RequisitionStatus::Declined, $requisition->status);
        $this->assertEquals($user->id, $requisition->declined_by);
        $this->assertEquals('Not needed at this time', $requisition->decline_reason);
    }

    public function test_issue_transitions_to_issued_when_all_lines_fulfilled(): void
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

        $service = app(RequisitionService::class);

        $requisition = $service->create([
            'branch_id' => $branch->id,
            'requestor_id' => $user->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 20],
            ],
        ]);

        $service->approve($requisition);
        $service->issue($requisition->items->first(), 10);
        $requisition->refresh();
        $this->assertEquals(RequisitionStatus::PartiallyIssued, $requisition->status);

        $service->issue($requisition->items()->first(), 10);
        $requisition->refresh();
        $this->assertEquals(RequisitionStatus::Issued, $requisition->status);
    }

    public function test_close_partially_issued_requisition(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        $service = app(RequisitionService::class);

        $requisition = $service->create([
            'branch_id' => $branch->id,
            'requestor_id' => $user->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 20],
            ],
        ]);

        $service->approve($requisition);
        $requisition->update(['status' => RequisitionStatus::PartiallyIssued]);
        $service->close($requisition, 'Stock unavailable');
        $requisition->refresh();

        $this->assertEquals(RequisitionStatus::Closed, $requisition->status);
        $this->assertEquals('Stock unavailable', $requisition->closed_reason);
    }
}
