<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Policies\RequisitionPolicy;
use Modules\Inventory\Policies\StorePolicy;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    private function departmentForBranch(Branch $branch): Department
    {
        $department = Department::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $department->locations()->attach($location->id, ['is_primary' => true]);

        return $department;
    }

    private function receive(InventoryItem $item, Branch $branch, int $qty, ?string $storeId = null): void
    {
        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: $qty,
            transactionType: TransactionType::Receive,
            storeId: $storeId,
        );
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_central_stock_without_a_store_lands_in_the_branch_main_store(): void
    {
        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $this->receive($item, $branch, 20);

        $main = Store::query()->where('branch_id', $branch->id)->where('is_main', true)->sole();
        $balance = StockBalance::query()->where('inventory_item_id', $item->id)->sole();

        $this->assertSame($main->id, $balance->store_id);
        $this->assertSame(20, StockBalance::dispensaryOnHand($item->id, $branch->id));
    }

    public function test_factory_created_central_balance_gets_main_store_and_ward_balance_gets_none(): void
    {
        $branch = Branch::factory()->create();

        $central = StockBalance::factory()->create(['branch_id' => $branch->id, 'location_type' => 'dispensary']);
        $ward = StockBalance::factory()->create(['branch_id' => $branch->id, 'location_type' => 'ward']);

        $this->assertSame(Store::mainIdForBranch($branch->id), $central->store_id);
        $this->assertNull($ward->store_id);
    }

    public function test_stock_is_tracked_separately_per_store(): void
    {
        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();
        $linen = Store::factory()->create(['branch_id' => $branch->id, 'name' => 'Linen Store', 'code' => 'LIN']);

        $this->receive($item, $branch, 10);
        $this->receive($item, $branch, 4, $linen->id);

        $this->assertSame(10, StockBalance::dispensaryOnHand($item->id, $branch->id));
        $this->assertSame(4, StockBalance::dispensaryOnHand($item->id, $branch->id, $linen->id));
    }

    public function test_store_from_another_branch_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $otherStore = Store::factory()->create();
        $item = InventoryItem::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not belong to this branch');

        $this->receive($item, $branch, 5, $otherStore->id);
    }

    public function test_requisition_issues_from_its_own_store_only(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $department = $this->departmentForBranch($branch);
        $item = InventoryItem::factory()->create();
        $medical = Store::factory()->create(['branch_id' => $branch->id, 'name' => 'Medical Stores', 'code' => 'MED']);

        $this->receive($item, $branch, 50);
        $this->receive($item, $branch, 3, $medical->id);

        $service = app(RequisitionService::class);
        $requisition = $service->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'store_id' => $medical->id,
            'items' => [['inventory_item_id' => $item->id, 'quantity_requested' => 3]],
        ]);
        $service->approve($requisition);

        $this->assertSame($medical->id, $requisition->store_id);

        $service->issue($requisition->items->first(), 2);

        $this->assertSame(1, StockBalance::dispensaryOnHand($item->id, $branch->id, $medical->id));
        $this->assertSame(50, StockBalance::dispensaryOnHand($item->id, $branch->id), 'Main store stock must be untouched.');
    }

    public function test_requisition_cannot_issue_more_than_its_store_holds(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $department = $this->departmentForBranch($branch);
        $item = InventoryItem::factory()->create();
        $medical = Store::factory()->create(['branch_id' => $branch->id, 'code' => 'MED']);

        $this->receive($item, $branch, 50);
        $this->receive($item, $branch, 1, $medical->id);

        $service = app(RequisitionService::class);
        $requisition = $service->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'store_id' => $medical->id,
            'items' => [['inventory_item_id' => $item->id, 'quantity_requested' => 3]],
        ]);
        $service->approve($requisition);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock on hand.');

        $service->issue($requisition->items->first(), 3);
    }

    public function test_requisition_defaults_to_main_store(): void
    {
        $this->actingAs(User::factory()->create());

        $branch = Branch::factory()->create();
        $department = $this->departmentForBranch($branch);
        $item = InventoryItem::factory()->create();

        $requisition = app(RequisitionService::class)->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'items' => [['inventory_item_id' => $item->id, 'quantity_requested' => 1]],
        ]);

        $this->assertSame(Store::mainIdForBranch($branch->id), $requisition->store_id);
        $this->assertSame(Store::mainIdForBranch($branch->id), Requisition::factory()->create(['branch_id' => $branch->id])->store_id);
    }

    public function test_marking_a_store_main_demotes_the_previous_main_store(): void
    {
        $branch = Branch::factory()->create();
        $main = Store::mainForBranch($branch->id);

        $new = Store::factory()->create(['branch_id' => $branch->id, 'code' => 'GEN']);
        $new->update(['is_main' => true]);

        $this->assertFalse($main->fresh()->is_main);
        $this->assertSame($new->id, Store::mainIdForBranch($branch->id));
    }

    public function test_main_store_cannot_be_unmarked_or_deactivated(): void
    {
        $main = Store::mainForBranch(Branch::factory()->create()->id);

        $this->expectException(\RuntimeException::class);

        $main->update(['is_main' => false]);
    }

    public function test_only_assigned_keepers_can_issue_once_a_store_has_keepers(): void
    {
        $branch = Branch::factory()->create();
        $store = Store::factory()->create(['branch_id' => $branch->id, 'code' => 'MED']);
        $requisition = Requisition::factory()->create(['branch_id' => $branch->id, 'store_id' => $store->id]);

        $keeper = $this->userWith('Issue Requisition');
        $otherIssuer = $this->userWith('Issue Requisition');
        $storesManager = $this->userWith('Issue Requisition', 'manage_all_stores');
        $policy = new RequisitionPolicy;

        // No keepers yet: anyone with the permission can issue (legacy behaviour).
        $this->assertTrue($policy->issue($otherIssuer, $requisition));

        $store->keepers()->attach($keeper->id);
        $requisition->unsetRelation('store');

        $this->assertTrue($policy->issue($keeper, $requisition));
        $this->assertFalse($policy->issue($otherIssuer, $requisition));
        $this->assertTrue($policy->issue($storesManager, $requisition));
        $this->assertFalse($policy->issue(User::factory()->create(), $requisition));
    }

    public function test_store_with_stock_or_main_store_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();
        $admin = $this->userWith('Delete Store');
        $policy = new StorePolicy;

        $empty = Store::factory()->create(['branch_id' => $branch->id, 'code' => 'EMP']);
        $stocked = Store::factory()->create(['branch_id' => $branch->id, 'code' => 'STK']);
        $this->receive($item, $branch, 2, $stocked->id);

        $this->assertTrue($policy->delete($admin, $empty));
        $this->assertFalse($policy->delete($admin, $stocked));
        $this->assertFalse($policy->delete($admin, Store::mainForBranch($branch->id)));
    }
}
