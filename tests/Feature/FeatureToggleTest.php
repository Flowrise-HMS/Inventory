<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Core\Settings\FeatureSettings;
use Modules\Inventory\Classes\Services\InterBranchTransferService;
use Modules\Inventory\Classes\Services\IssueToWardService;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Models\InventoryItem;
use Tests\TestCase;

class FeatureToggleTest extends TestCase
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

    public function test_ward_issue_denied_when_ward_requisitions_disabled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $settings = app(FeatureSettings::class);
        $settings->inventory_ward_requisitions = false;

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
        $this->expectExceptionMessage('Inventory ward requisitions are disabled.');

        app(IssueToWardService::class)->issue($requisition->items->first(), 5);
    }

    public function test_transfer_create_denied_when_inter_branch_disabled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $settings = app(FeatureSettings::class);
        $settings->inventory_inter_branch_transfers = false;

        $fromBranch = Branch::factory()->create();
        $toBranch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inter-branch stock transfers are disabled.');

        app(InterBranchTransferService::class)->create([
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 10],
            ],
        ]);
    }
}
