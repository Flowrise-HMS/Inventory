<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Pages\ListStockBalances;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\Pages\ViewStockBalance;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\StockBalances\StockBalanceResource;
use Modules\Inventory\Models\StockBalance;
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
    'resource' => StockBalanceResource::class,
    'subject' => 'StockBalance',
    'model' => StockBalance::class,
    'listPage' => ListStockBalances::class,
    'viewPage' => ViewStockBalance::class,
    'sortColumn' => 'expiry_date',
    'hasBulkDelete' => false,
    'hasRecordDelete' => false,
    'userAttributes' => fn (TestCase $test): array => ['branch_id' => $test->branch->id],
    'makeRecord' => fn (TestCase $test, array $attributes = []): StockBalance => StockBalance::factory()->create([
        'branch_id' => $test->branch->id,
        ...$attributes,
    ]),
    'makeRecords' => function (TestCase $test, int $count) {
        return collect(range(1, $count))->map(fn (int $offset): StockBalance => StockBalance::factory()->create([
            'branch_id' => $test->branch->id,
            'expiry_date' => now()->addDays($offset)->toDateString(),
        ]));
    },
]);

it('lets an authorised user change the reorder point from the list', function (): void {
    $this->actingAs(App\Models\User::factory()->create(['branch_id' => $this->branch->id]));
    Illuminate\Support\Facades\Gate::before(fn (): bool => true);

    $balance = StockBalance::factory()->create([
        'branch_id' => $this->branch->id,
        'reorder_point' => 20,
    ]);

    Livewire\Livewire::test(ListStockBalances::class)
        ->callTableAction('setReorderPoint', $balance, data: ['reorder_point' => 200])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Reorder point updated');

    expect($balance->fresh()->reorder_point)->toBe(200);
});
