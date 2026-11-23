<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Enums\StoreType;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages\CreateStore;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages\EditStore;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages\ListStores;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\Pages\ViewStore;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Stores\StoreResource;
use Modules\Inventory\Models\Store;
use Tests\Support\FilamentResourceTestSuite;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $this->requireModule('Inventory');
    $this->migrateModules(['Core', 'Inventory']);
});

FilamentResourceTestSuite::register([
    'resource' => StoreResource::class,
    'subject' => 'Store',
    'model' => Store::class,
    'listPage' => ListStores::class,
    'createPage' => CreateStore::class,
    'editPage' => EditStore::class,
    'viewPage' => ViewStore::class,
    'searchColumn' => 'name',
    'sortColumn' => 'name',
    'filter' => [
        'name' => 'is_active',
        'value' => true,
        'attribute' => 'is_active',
    ],
    'hasRecordDelete' => true,
    'createForm' => fn (): array => [
        'name' => 'Medical Stores '.fake()->unique()->numerify('###'),
        'code' => fake()->unique()->bothify('med-###'),
        'branch_id' => Branch::factory()->create()->id,
        'type' => StoreType::Medical->value,
        'is_main' => false,
        'is_active' => true,
    ],
    'updateForm' => fn (): array => [
        'name' => 'Renamed Store '.fake()->unique()->numerify('###'),
        'type' => StoreType::Linen->value,
    ],
    'schemaState' => fn (mixed $test, Store $record): array => [
        'name' => $record->name,
        'code' => $record->code,
    ],
    'requiredValidation' => [
        'name is required' => [['name' => null], ['name' => 'required']],
        'code is required' => [['code' => null], ['code' => 'required']],
    ],
    'databaseHasOnCreate' => fn (mixed $test, array $payload): array => [
        'name' => $payload['name'],
        // Codes are normalised to upper case.
        'code' => strtoupper($payload['code']),
        'branch_id' => $payload['branch_id'],
    ],
]);
