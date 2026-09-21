<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages\CreateSupplier;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages\EditSupplier;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages\ListSuppliers;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\Pages\ViewSupplier;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\Suppliers\SupplierResource;
use Modules\Inventory\Models\Supplier;
use Tests\Support\FilamentResourceTestSuite;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $this->requireModule('Inventory');
    $this->migrateModules(['Core', 'Inventory']);
});

FilamentResourceTestSuite::register([
    'resource' => SupplierResource::class,
    'subject' => 'Supplier',
    'model' => Supplier::class,
    'listPage' => ListSuppliers::class,
    'createPage' => CreateSupplier::class,
    'editPage' => EditSupplier::class,
    'viewPage' => ViewSupplier::class,
    'searchColumn' => 'name',
    'sortColumn' => 'name',
    'filter' => [
        'name' => 'is_active',
        'value' => true,
        'attribute' => 'is_active',
    ],
    'hasBulkDelete' => true,
    'hasRecordDelete' => true,
    'createForm' => fn (): array => [
        'name' => fake()->unique()->company().' Medical Supplies',
        'contact_person' => 'Ama Supplier',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '+233244111111',
        'is_active' => true,
    ],
    'updateForm' => fn (): array => [
        'name' => 'Updated '.fake()->unique()->company(),
        'is_active' => true,
    ],
    'schemaState' => fn (mixed $test, Supplier $record): array => [
        'name' => $record->name,
    ],
    'requiredValidation' => [
        'name is required' => [['name' => null], ['name' => 'required']],
        'email must be valid' => [['email' => 'not-an-email'], ['email' => 'email']],
    ],
    'databaseHasOnCreate' => fn (mixed $test, array $payload): array => [
        'name' => $payload['name'],
        'email' => $payload['email'],
    ],
]);
