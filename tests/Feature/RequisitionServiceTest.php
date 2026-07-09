<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Enums\RequisitionStatus;
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
}
