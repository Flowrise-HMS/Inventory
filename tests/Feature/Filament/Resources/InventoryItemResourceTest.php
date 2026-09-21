<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Unit;
use Modules\Inventory\Enums\InventoryItemCategory;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\InventoryItemResource;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages\CreateInventoryItem;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages\EditInventoryItem;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages\ListInventoryItems;
use Modules\Inventory\Filament\Clusters\Inventory\Resources\InventoryItems\Pages\ViewInventoryItem;
use Modules\Inventory\Models\InventoryItem;
use Tests\Support\FilamentResourceTestSuite;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $this->requireModule('Inventory');
    $this->migrateModules(['Core', 'Inventory']);
    $this->unit = Unit::factory()->create();
});

FilamentResourceTestSuite::register([
    'resource' => InventoryItemResource::class,
    'subject' => 'InventoryItem',
    'model' => InventoryItem::class,
    'listPage' => ListInventoryItems::class,
    'createPage' => CreateInventoryItem::class,
    'editPage' => EditInventoryItem::class,
    'viewPage' => ViewInventoryItem::class,
    'searchColumn' => 'name',
    'sortColumn' => 'name',
    'filter' => [
        'name' => 'category',
        'value' => InventoryItemCategory::Supplies->value,
        'attribute' => 'category',
    ],
    'hasBulkDelete' => true,
    'hasRecordDelete' => true,
    'makeRecords' => fn (TestCase $test, int $count) => InventoryItem::factory()->count($count)->create([
        'category' => InventoryItemCategory::Supplies,
        'unit_id' => $test->unit->id,
    ]),
    'createForm' => fn (TestCase $test): array => [
        'name' => fake()->unique()->words(3, true).' Gloves',
        'sku' => fake()->unique()->bothify('SKU-####'),
        'category' => InventoryItemCategory::Supplies->value,
        'unit_id' => $test->unit->id,
        'is_active' => true,
    ],
    'updateForm' => fn (): array => [
        'name' => 'Updated inventory item',
        'is_active' => true,
    ],
    'schemaState' => fn (mixed $test, InventoryItem $record): array => [
        'name' => $record->name,
        'category' => $record->category,
    ],
    'requiredValidation' => [
        'name is required' => [['name' => null], ['name' => 'required']],
        'category is required' => [['category' => null], ['category' => 'required']],
        'unit is required' => [['unit_id' => null], ['unit_id' => 'required']],
    ],
    'databaseHasOnCreate' => fn (mixed $test, array $payload): array => [
        'name' => $payload['name'],
        'category' => $payload['category'],
    ],
]);
