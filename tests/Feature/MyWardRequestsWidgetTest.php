<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Enums\RequisitionStatus;
use Modules\Inventory\Filament\Widgets\MyWardRequestsWidget;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;
use Tests\TestCase;

class MyWardRequestsWidgetTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);

        // Filament resource policies are gated by Shield permissions that aren't
        // seeded in the test database; bypass authorization for these UI tests.
        Gate::before(fn (): bool => true);

        Filament::setCurrentPanel(Filament::getDefaultPanel());
    }

    private function createDepartmentForBranch(Branch $branch): Department
    {
        $department = Department::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $department->locations()->attach($location->id, ['is_primary' => true]);

        return $department;
    }

    public function test_widget_scopes_to_current_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);

        Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Pending,
        ]);

        Requisition::factory()->create([
            'requestor_id' => $otherUser->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Pending,
        ]);

        $this->actingAs($user);

        $query = Requisition::query()
            ->where('requestor_id', auth()->id());

        $this->assertCount(1, $query->get());
        $this->assertEquals($user->id, $query->first()->requestor_id);
    }

    public function test_widget_queries_only_own_requisitions(): void
    {
        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);

        $pending = Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Pending,
        ]);

        $issued = Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Issued,
        ]);

        $this->actingAs($user);

        $results = Requisition::query()
            ->where('requestor_id', auth()->id())
            ->latest()
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $pending->id));
        $this->assertTrue($results->contains('id', $issued->id));
    }

    public function test_widget_lists_own_requisitions_with_their_line_items(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create(['name' => 'Surgical Gloves', 'sku' => 'GLV-001']);

        $requisition = Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Pending,
        ]);

        $requisition->items()->create([
            'inventory_item_id' => $item->id,
            'quantity_requested' => 15,
        ]);

        Livewire::test(MyWardRequestsWidget::class)
            ->assertCanSeeTableRecords([$requisition])
            ->assertSee('Surgical Gloves');
    }

    public function test_new_request_header_action_does_not_use_a_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(MyWardRequestsWidget::class)
            ->assertActionExists(
                TestAction::make('new_request')->table(),
                fn (Action $action): bool => blank($action->getUrl()),
            );
    }

    public function test_can_create_a_requisition_inline_with_items_via_the_slide_over(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $itemOne = InventoryItem::factory()->create();
        $itemTwo = InventoryItem::factory()->create();

        Livewire::test(MyWardRequestsWidget::class)
            ->mountAction(TestAction::make('new_request')->table())
            ->fillForm(fn (array $state): array => [
                'branch_id' => $branch->id,
                'department_id' => $department->id,
                'notes' => 'Urgent restock',
                'items' => [
                    [
                        'inventory_item_id' => $itemOne->id,
                        'quantity_requested' => 12,
                    ],
                    [
                        'inventory_item_id' => $itemTwo->id,
                        'quantity_requested' => 4,
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $requisition = Requisition::query()->where('requestor_id', $user->id)->firstOrFail();

        $this->assertEquals($user->id, $requisition->requestor_id);
        $this->assertEquals('pending', $requisition->status->value);
        $this->assertCount(2, $requisition->items);
        $this->assertEquals(12, $requisition->items->firstWhere('inventory_item_id', $itemOne->id)->quantity_requested);
        $this->assertEquals(4, $requisition->items->firstWhere('inventory_item_id', $itemTwo->id)->quantity_requested);
    }

    public function test_view_action_opens_in_a_slide_over_without_a_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $requisition = Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
        ]);

        Livewire::test(MyWardRequestsWidget::class)
            ->assertActionExists(
                TestAction::make('view')->table($requisition),
                fn (Action $action): bool => blank($action->getUrl()),
            );
    }

    public function test_cancel_action_is_only_visible_while_pending(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);

        $pending = Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Pending,
        ]);

        $issued = Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Issued,
        ]);

        Livewire::test(MyWardRequestsWidget::class)
            ->assertActionVisible(TestAction::make('cancel')->table($pending))
            ->assertActionHidden(TestAction::make('cancel')->table($issued));
    }

    public function test_fulfill_action_visible_for_approved_requisition(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);

        $approved = Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Approved,
        ]);

        Livewire::test(MyWardRequestsWidget::class)
            ->assertActionVisible(TestAction::make('fulfill')->table($approved));
    }

    public function test_cancel_action_cancels_a_pending_requisition_without_a_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);

        $requisition = Requisition::factory()->create([
            'requestor_id' => $user->id,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'status' => RequisitionStatus::Pending,
        ]);

        Livewire::test(MyWardRequestsWidget::class)
            ->assertActionExists(
                TestAction::make('cancel')->table($requisition),
                fn (Action $action): bool => blank($action->getUrl()),
            )
            ->callAction(TestAction::make('cancel')->table($requisition));

        $this->assertEquals(RequisitionStatus::Cancelled, $requisition->refresh()->status);
    }
}
