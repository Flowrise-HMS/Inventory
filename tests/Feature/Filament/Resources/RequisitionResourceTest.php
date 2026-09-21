<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages\CreateRequisition;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages\EditRequisition;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages\ListRequisitions;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\Pages\ViewRequisition;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Requisitions\RequisitionResource;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\Requisition;
use Tests\Support\FilamentResourceTestSuite;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $this->requireModule('Inventory');
    $this->migrateModules(['Core', 'Inventory']);
    $this->branch = Branch::factory()->create();
    $this->setCurrentBranch($this->branch);
    $this->department = Department::factory()->create();
    $this->item = InventoryItem::factory()->create();
});

FilamentResourceTestSuite::register([
    'resource' => RequisitionResource::class,
    'subject' => 'Requisition',
    'model' => Requisition::class,
    'listPage' => ListRequisitions::class,
    'createPage' => CreateRequisition::class,
    'editPage' => EditRequisition::class,
    'viewPage' => ViewRequisition::class,
    'searchColumn' => 'requisition_number',
    'sortColumn' => 'requisition_number',
    'hasBulkDelete' => true,
    'hasRecordDelete' => true,
    'userAttributes' => fn (TestCase $test): array => ['branch_id' => $test->branch->id],
    'makeRecord' => fn (TestCase $test, array $attributes = []): Requisition => Requisition::factory()->create([
        'branch_id' => $test->branch->id,
        'department_id' => $test->department->id,
        'requestor_id' => auth()->id(),
        ...$attributes,
    ]),
    'makeRecords' => fn (TestCase $test, int $count) => Requisition::factory()->count($count)->create([
        'branch_id' => $test->branch->id,
        'department_id' => $test->department->id,
        'requestor_id' => auth()->id(),
    ]),
    'createForm' => fn (TestCase $test): array => [
        'requestor_id' => auth()->id(),
        'department_id' => $test->department->id,
        'branch_id' => $test->branch->id,
        'items' => [
            [
                'inventory_item_id' => $test->item->id,
                'quantity_requested' => 3,
            ],
        ],
    ],
    'updateForm' => fn (TestCase $test): array => [
        'requestor_id' => auth()->id(),
        'department_id' => $test->department->id,
        'branch_id' => $test->branch->id,
    ],
    'schemaState' => fn (mixed $test, Requisition $record): array => [
        'department_id' => $record->department_id,
        'branch_id' => $record->branch_id,
    ],
    'requiredValidation' => [
        'requestor is required' => [['requestor_id' => null], ['requestor_id' => 'required']],
        'department is required' => [['department_id' => null], ['department_id' => 'required']],
        'branch is required' => [['branch_id' => null], ['branch_id' => 'required']],
    ],
    'databaseHasOnCreate' => fn (mixed $test, array $payload): array => [
        'department_id' => $payload['department_id'],
        'branch_id' => $payload['branch_id'],
    ],
]);

it('renders the requisition items on the view page', function (): void {
    $this->actingAs(App\Models\User::factory()->create(['branch_id' => $this->branch->id]));
    Illuminate\Support\Facades\Gate::before(fn (): bool => true);

    $requisition = Requisition::factory()->create([
        'branch_id' => $this->branch->id,
        'department_id' => $this->department->id,
        'requestor_id' => auth()->id(),
    ]);
    $requisition->items()->create([
        'inventory_item_id' => $this->item->id,
        'quantity_requested' => 3,
    ]);

    Livewire\Livewire::test(ViewRequisition::class, ['record' => $requisition->getRouteKey()])
        ->assertOk()
        ->assertSee($this->item->name)
        ->assertSee('Requested: 3');
});
