<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Core\Models\Location;
use Modules\Core\Models\Unit;
use Modules\Inventory\Classes\Services\PurchaseOrderService;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\Requisition;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Supplier;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PdfGenerationTest extends TestCase
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

    public function test_grn_pdf_returns_pdf_with_permission(): void
    {
        Permission::firstOrCreate(['name' => 'print_inventory_document', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('print_inventory_document');
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = InventoryItem::factory()->create();

        $service = app(PurchaseOrderService::class);
        $po = $service->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_ordered' => 10],
            ],
        ]);
        $service->submit($po);
        $po->refresh();

        $service->receive($po, [
            'received_at' => now(),
            'items' => [
                ['purchase_order_item_id' => $po->items->first()->id, 'quantity_received' => 10],
            ],
        ]);

        $response = $this->get(route('inventory.purchase-orders.grn', $po));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_grn_pdf_returns_403_without_permission(): void
    {
        Permission::firstOrCreate(['name' => 'print_inventory_document', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = InventoryItem::factory()->create();

        $service = app(PurchaseOrderService::class);
        $po = $service->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_ordered' => 10],
            ],
        ]);
        $service->submit($po);
        $po->refresh();

        $service->receive($po, [
            'received_at' => now(),
            'items' => [
                ['purchase_order_item_id' => $po->items->first()->id, 'quantity_received' => 10],
            ],
        ]);

        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);

        $response = $this->get(route('inventory.purchase-orders.grn', $po));

        $response->assertForbidden();
    }

    public function test_requisition_voucher_pdf_returns_pdf_with_permission(): void
    {
        Permission::firstOrCreate(['name' => 'print_inventory_document', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('print_inventory_document');
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $department = $this->createDepartmentForBranch($branch);
        $item = InventoryItem::factory()->create();
        $unit = Unit::factory()->create();

        StockBalance::factory()->create([
            'inventory_item_id' => $item->id,
            'branch_id' => $branch->id,
            'location_type' => 'dispensary',
            'quantity_on_hand' => 100,
            'unit_id' => $unit->id,
        ]);

        $requisition = Requisition::factory()->create([
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'requestor_id' => $user->id,
            'status' => 'issued',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'issued_by' => $user->id,
            'issued_at' => now(),
        ]);

        $requisition->items()->create([
            'inventory_item_id' => $item->id,
            'quantity_requested' => 20,
            'quantity_approved' => 20,
            'quantity_issued' => 20,
        ]);

        $response = $this->get(route('inventory.requisitions.voucher', $requisition));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_stock_card_pdf_returns_pdf_with_permission(): void
    {
        Permission::firstOrCreate(['name' => 'print_inventory_document', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('print_inventory_document');
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'delta' => 100,
            'quantity_after' => 100,
            'transaction_type' => TransactionType::Receive,
            'branch_id' => $branch->id,
            'performed_by' => $user->id,
        ]);

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'delta' => -10,
            'quantity_after' => 90,
            'transaction_type' => TransactionType::Issue,
            'branch_id' => $branch->id,
            'performed_by' => $user->id,
        ]);

        $response = $this->get(route('inventory.items.stock-card', $item));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_pdf_download_with_download_param(): void
    {
        Permission::firstOrCreate(['name' => 'download_inventory_document', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('download_inventory_document');
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        $response = $this->get(route('inventory.items.stock-card', ['item' => $item, 'download' => 1]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition') ?? '');
    }
}
