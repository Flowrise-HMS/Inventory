<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\StockAdjustmentService;
use Modules\Inventory\Classes\Services\StockLedgerService;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Tests\TestCase;

class StockAdjustmentServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_adjusts_dispensary_quantity_up_and_down(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 10,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $service = app(StockAdjustmentService::class);

        $service->adjust(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            newQty: 25,
        );

        $this->assertSame(25, StockBalance::dispensaryOnHand($item->id, $branch->id));

        $service->adjust(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            newQty: 20,
        );

        $this->assertSame(20, StockBalance::dispensaryOnHand($item->id, $branch->id));
    }

    public function test_throws_when_new_quantity_matches_current(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::factory()->create();
        $item = InventoryItem::factory()->create();

        app(StockLedgerService::class)->lockAndIncrement(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            stockTransferId: null,
            qty: 10,
            transactionType: TransactionType::Receive,
            reference: null,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('New quantity matches current on-hand quantity.');

        app(StockAdjustmentService::class)->adjust(
            itemId: $item->id,
            branchId: $branch->id,
            locationType: StockLocationType::Dispensary,
            departmentId: null,
            newQty: 10,
        );
    }
}
