<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\PurchaseOrderService;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_can_create_purchase_order_with_items(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $service = app(PurchaseOrderService::class);
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = InventoryItem::factory()->create();

        $po = $service->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_ordered' => 50],
            ],
        ]);

        $this->assertNotNull($po->po_number);
        $this->assertEquals('draft', $po->status->value);
        $this->assertCount(1, $po->items);
        $this->assertEquals(50, $po->items->first()->quantity_ordered);
    }

    public function test_submit_changes_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $service = app(PurchaseOrderService::class);
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = InventoryItem::factory()->create();

        $po = $service->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity_ordered' => 50],
            ],
        ]);

        $service->submit($po);
        $po->refresh();

        $this->assertEquals(PurchaseOrderStatus::Submitted, $po->status);
    }

    public function test_receive_full_po_updates_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $service = app(PurchaseOrderService::class);
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = InventoryItem::factory()->create();

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
            'items' => [
                [
                    'purchase_order_item_id' => $po->items->first()->id,
                    'quantity_received' => 10,
                ],
            ],
        ]);

        $po->refresh();

        $this->assertEquals(PurchaseOrderStatus::Received, $po->status);
        $this->assertEquals(10, $po->items->first()->quantity_received);
    }
}
