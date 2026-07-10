<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\AutoReorderService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Supplier;
use Tests\TestCase;

class AutoReorderServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_suggestions_include_dispensary_balances_at_or_below_reorder_point(): void
    {
        $branch = Branch::factory()->create();
        $lowItem = InventoryItem::factory()->create(['name' => 'Gloves']);
        $healthyItem = InventoryItem::factory()->create(['name' => 'Masks']);

        app(StockLedgerService::class)->addOpeningStock(
            itemId: $lowItem->id,
            branchId: $branch->id,
            qty: 2,
            reorderPoint: 10,
        );

        app(StockLedgerService::class)->addOpeningStock(
            itemId: $healthyItem->id,
            branchId: $branch->id,
            qty: 50,
            reorderPoint: 10,
        );

        $suggestions = app(AutoReorderService::class)->suggestions($branch->id);

        $this->assertCount(1, $suggestions);
        $this->assertSame($lowItem->id, $suggestions[0]['inventory_item_id']);
        $this->assertSame(8, $suggestions[0]['quantity_to_order']);
    }

    public function test_create_draft_purchase_order_from_suggestions(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = InventoryItem::factory()->create();

        app(StockLedgerService::class)->addOpeningStock(
            itemId: $item->id,
            branchId: $branch->id,
            qty: 1,
            reorderPoint: 5,
        );

        $purchaseOrder = app(AutoReorderService::class)->createDraftPurchaseOrder(
            supplierId: $supplier->id,
            branchId: $branch->id,
            items: [[
                'inventory_item_id' => $item->id,
                'quantity_ordered' => 4,
            ]],
        );

        $this->assertSame(PurchaseOrderStatus::Draft, $purchaseOrder->status);
        $this->assertSame($supplier->id, $purchaseOrder->supplier_id);
        $this->assertCount(1, $purchaseOrder->items);
        $this->assertSame(4, (int) $purchaseOrder->items->first()->quantity_ordered);
    }
}
