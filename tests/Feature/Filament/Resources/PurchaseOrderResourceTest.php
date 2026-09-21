<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\PurchaseOrders\PurchaseOrderResource;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\Supplier;
use Tests\Support\FilamentResourceTestSuite;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $this->requireModule('Inventory');
    $this->migrateModules(['Core', 'Inventory']);
    $this->branch = Branch::factory()->create();
    $this->setCurrentBranch($this->branch);
    $this->supplier = Supplier::factory()->create();
    $this->item = InventoryItem::factory()->create();
});

FilamentResourceTestSuite::register([
    'resource' => PurchaseOrderResource::class,
    'subject' => 'PurchaseOrder',
    'model' => PurchaseOrder::class,
    'listPage' => ListPurchaseOrders::class,
    'createPage' => CreatePurchaseOrder::class,
    'editPage' => EditPurchaseOrder::class,
    'viewPage' => ViewPurchaseOrder::class,
    'searchColumn' => 'po_number',
    'sortColumn' => 'po_number',
    'hasBulkDelete' => true,
    'hasRecordDelete' => true,
    'userAttributes' => fn (TestCase $test): array => ['branch_id' => $test->branch->id],
    'makeRecord' => fn (TestCase $test, array $attributes = []): PurchaseOrder => PurchaseOrder::factory()->create([
        'branch_id' => $test->branch->id,
        'supplier_id' => $test->supplier->id,
        ...$attributes,
    ]),
    'makeRecords' => fn (TestCase $test, int $count) => PurchaseOrder::factory()->count($count)->create([
        'branch_id' => $test->branch->id,
        'supplier_id' => $test->supplier->id,
    ]),
    'createForm' => fn (TestCase $test): array => [
        'supplier_id' => $test->supplier->id,
        'branch_id' => $test->branch->id,
        'ordered_at' => now()->toDateTimeString(),
        'items' => [
            [
                'inventory_item_id' => $test->item->id,
                'quantity_ordered' => 5,
            ],
        ],
    ],
    'updateForm' => fn (TestCase $test): array => [
        'supplier_id' => $test->supplier->id,
        'branch_id' => $test->branch->id,
        'ordered_at' => now()->toDateTimeString(),
    ],
    'schemaState' => fn (mixed $test, PurchaseOrder $record): array => [
        'supplier_id' => $record->supplier_id,
        'branch_id' => $record->branch_id,
    ],
    'requiredValidation' => [
        'supplier is required' => [['supplier_id' => null], ['supplier_id' => 'required']],
        'branch is required' => [['branch_id' => null], ['branch_id' => 'required']],
        'ordered at is required' => [['ordered_at' => null], ['ordered_at' => 'required']],
    ],
    'databaseHasOnCreate' => fn (mixed $test, array $payload): array => [
        'supplier_id' => $payload['supplier_id'],
        'branch_id' => $payload['branch_id'],
    ],
]);

it('creates a draft purchase order from the low-stock suggestions', function (): void {
    $this->actingAs(App\Models\User::factory()->create(['branch_id' => $this->branch->id]));
    Illuminate\Support\Facades\Gate::before(fn (): bool => true);

    $item = InventoryItem::factory()->create(['name' => 'UI QA Gloves']);
    app(Modules\Inventory\Classes\Services\StockLedgerService::class)->addOpeningStock(
        itemId: $item->id,
        branchId: $this->branch->id,
        qty: 100,
        reorderPoint: 200,
    );

    $page = Livewire\Livewire::test(ListPurchaseOrders::class)
        ->mountAction('generate_reorder')
        ->assertSchemaStateSet(['branch_id' => $this->branch->id], 'mountedActionSchema0')
        ->assertSchemaStateSet(function (array $state) use ($item): bool {
            $lines = array_values($state['items'] ?? []);

            return count($lines) === 1
                && $lines[0]['inventory_item_id'] === $item->id
                && (int) $lines[0]['quantity_ordered'] === 100;
        }, 'mountedActionSchema0')
        ->setActionData(['supplier_id' => $this->supplier->id])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Draft purchase order created');

    $purchaseOrder = PurchaseOrder::query()->where('supplier_id', $this->supplier->id)->latest()->firstOrFail();

    expect($purchaseOrder->status)->toBe(Modules\Inventory\Enums\PurchaseOrderStatus::Draft)
        ->and($purchaseOrder->items()->where('inventory_item_id', $item->id)->value('quantity_ordered'))->toBe(100);
});
