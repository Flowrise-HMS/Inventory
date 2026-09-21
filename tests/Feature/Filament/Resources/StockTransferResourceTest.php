<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages\CreateStockTransfer;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages\EditStockTransfer;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages\ListStockTransfers;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\Pages\ViewStockTransfer;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockTransfers\StockTransferResource;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockTransfer;
use Tests\Support\FilamentResourceTestSuite;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $this->requireModule('Inventory');
    $this->migrateModules(['Core', 'Inventory']);
    $this->fromBranch = Branch::factory()->create();
    $this->toBranch = Branch::factory()->create();
    $this->setCurrentBranch($this->fromBranch);
    $this->item = InventoryItem::factory()->create();
});

FilamentResourceTestSuite::register([
    'resource' => StockTransferResource::class,
    'subject' => 'StockTransfer',
    'model' => StockTransfer::class,
    'listPage' => ListStockTransfers::class,
    'createPage' => CreateStockTransfer::class,
    'editPage' => EditStockTransfer::class,
    'viewPage' => ViewStockTransfer::class,
    'searchColumn' => 'transfer_number',
    'sortColumn' => 'transfer_number',
    'filter' => [
        'name' => 'status',
        'value' => StockTransferStatus::Draft->value,
        'attribute' => 'status',
    ],
    'hasBulkDelete' => true,
    'hasRecordDelete' => true,
    'userAttributes' => fn (TestCase $test): array => ['branch_id' => $test->fromBranch->id],
    'makeRecord' => fn (TestCase $test, array $attributes = []): StockTransfer => StockTransfer::factory()->create([
        'from_branch_id' => $test->fromBranch->id,
        'to_branch_id' => $test->toBranch->id,
        'status' => StockTransferStatus::Draft,
        ...$attributes,
    ]),
    'makeRecords' => fn (TestCase $test, int $count) => StockTransfer::factory()->count($count)->create([
        'from_branch_id' => $test->fromBranch->id,
        'to_branch_id' => $test->toBranch->id,
        'status' => StockTransferStatus::Draft,
    ]),
    'createForm' => fn (TestCase $test): array => [
        'from_branch_id' => $test->fromBranch->id,
        'to_branch_id' => $test->toBranch->id,
        'notes' => 'Ward restock',
        'items' => [
            [
                'inventory_item_id' => $test->item->id,
                'quantity_requested' => 2,
            ],
        ],
    ],
    'updateForm' => fn (TestCase $test): array => [
        'from_branch_id' => $test->fromBranch->id,
        'to_branch_id' => $test->toBranch->id,
        'notes' => 'Updated transfer notes',
    ],
    'schemaState' => fn (mixed $test, StockTransfer $record): array => [
        'from_branch_id' => $record->from_branch_id,
        'to_branch_id' => $record->to_branch_id,
    ],
    'requiredValidation' => [
        'from branch is required' => [['from_branch_id' => null], ['from_branch_id' => 'required']],
        'to branch is required' => [['to_branch_id' => null], ['to_branch_id' => 'required']],
    ],
    'databaseHasOnCreate' => fn (mixed $test, array $payload): array => [
        'from_branch_id' => $payload['from_branch_id'],
        'to_branch_id' => $payload['to_branch_id'],
    ],
]);
