<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Enums\TransactionType;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\InventoryTransactionResource;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Pages\ListInventoryTransactions;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryTransactions\Pages\ViewInventoryTransaction;
use Modules\Inventory\Models\InventoryTransaction;
use Tests\Support\FilamentResourceTestSuite;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $this->requireModule('Inventory');
    $this->migrateModules(['Core', 'Inventory']);
    $this->branch = Branch::factory()->create();
    $this->setCurrentBranch($this->branch);
});

FilamentResourceTestSuite::register([
    'resource' => InventoryTransactionResource::class,
    'subject' => 'InventoryTransaction',
    'model' => InventoryTransaction::class,
    'listPage' => ListInventoryTransactions::class,
    'viewPage' => ViewInventoryTransaction::class,
    'sortColumn' => 'unit_label_snapshot',
    'filter' => [
        'name' => 'transaction_type',
        'value' => TransactionType::Receive->value,
        'attribute' => 'transaction_type',
    ],
    'hasBulkDelete' => false,
    'hasRecordDelete' => false,
    'userAttributes' => fn (TestCase $test): array => ['branch_id' => $test->branch->id],
    'makeRecord' => fn (TestCase $test, array $attributes = []): InventoryTransaction => InventoryTransaction::factory()->create([
        'branch_id' => $test->branch->id,
        'transaction_type' => TransactionType::Receive,
        ...$attributes,
    ]),
    'makeRecords' => fn (TestCase $test, int $count) => InventoryTransaction::factory()->count($count)->create([
        'branch_id' => $test->branch->id,
        'transaction_type' => TransactionType::Receive,
    ]),
]);
