<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Events\RequisitionCreated;
use Modules\Inventory\Listeners\NotifyApproversOfRequisition;
use Modules\Inventory\Models\InventoryItem;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RequisitionNotificationTest extends TestCase
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

    public function test_requisition_create_dispatches_event(): void
    {
        Event::fake([RequisitionCreated::class]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        app(RequisitionService::class)->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 5],
            ],
        ]);

        Event::assertDispatched(RequisitionCreated::class);
    }

    public function test_listener_notifies_approvers(): void
    {
        Notification::fake();

        Permission::findOrCreate('Approve Requisition', 'web');

        $approver = User::factory()->create();
        $approver->givePermissionTo('Approve Requisition');

        $requestor = User::factory()->create();
        $this->actingAs($requestor);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        $requisition = app(RequisitionService::class)->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_requested' => 3],
            ],
        ]);

        app(NotifyApproversOfRequisition::class)->handle(new RequisitionCreated($requisition));

        Notification::assertSentTo($approver, DatabaseNotification::class);
    }
}
