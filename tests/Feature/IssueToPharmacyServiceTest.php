<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Core\Models\Service;
use Modules\Core\Models\Unit;
use Modules\Inventory\Classes\Services\IssueToPharmacyService;
use Modules\Inventory\Classes\Services\RequisitionService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\RequisitionItem;
use Modules\Inventory\Models\StockBalance;
use Modules\Pharmacy\Classes\Services\StockService;
use Modules\Pharmacy\Models\Medication;
use Tests\TestCase;

class IssueToPharmacyServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory', 'Pharmacy']);
    }

    private function createDepartmentForBranch(Branch $branch): Department
    {
        $department = Department::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $department->locations()->attach($location->id, ['is_primary' => true]);

        return $department;
    }

    public function test_issues_matching_units_to_pharmacy_stock(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $unit = Unit::factory()->create();
        $service = Service::factory()->create();
        $medication = Medication::factory()->create([
            'service_id' => $service->id,
            'stock_unit_id' => $unit->id,
            'units_per_stock_unit' => null,
        ]);

        $inventoryItem = InventoryItem::factory()->create([
            'medication_id' => $medication->id,
            'unit_id' => $unit->id,
        ]);

        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $inventoryItem->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 100,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $requisition = app(RequisitionService::class)->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $inventoryItem->id, 'quantity_requested' => 10],
            ],
        ]);

        app(RequisitionService::class)->approve($requisition);
        $item = $requisition->items->first();

        app(IssueToPharmacyService::class)->issue($item, 10);

        $item->refresh();

        $this->assertSame(10, $item->quantity_issued);
        $this->assertSame(90, StockBalance::dispensaryOnHand($inventoryItem->id, $branch->id));
        $this->assertSame(10, app(StockService::class)->getQuantityOnHand($branch->id, $medication->id));
    }

    public function test_converts_inventory_units_to_pharmacy_stock_units(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $boxUnit = Unit::factory()->create(['label' => 'Box']);
        $tabletUnit = Unit::factory()->create(['label' => 'Tablet']);
        $service = Service::factory()->create();
        $medication = Medication::factory()->create([
            'service_id' => $service->id,
            'stock_unit_id' => $tabletUnit->id,
            'units_per_stock_unit' => 10,
        ]);

        $inventoryItem = InventoryItem::factory()->create([
            'medication_id' => $medication->id,
            'unit_id' => $boxUnit->id,
        ]);

        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $inventoryItem->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 20,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $requisition = app(RequisitionService::class)->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'items' => [
                ['inventory_item_id' => $inventoryItem->id, 'quantity_requested' => 2],
            ],
        ]);

        app(RequisitionService::class)->approve($requisition);
        /** @var RequisitionItem $item */
        $item = $requisition->items->first();

        app(IssueToPharmacyService::class)->issue($item, 2);

        $item->refresh();

        $this->assertSame(2, $item->quantity_issued);
        $this->assertSame(18, StockBalance::dispensaryOnHand($inventoryItem->id, $branch->id));
        $this->assertSame(20, app(StockService::class)->getQuantityOnHand($branch->id, $medication->id));
    }
}
