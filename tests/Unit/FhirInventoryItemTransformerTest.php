<?php

use Illuminate\Support\Collection;
use Modules\Core\Enums\UnitCategory;
use Modules\Core\Models\Unit;
use Modules\FHIR\Contracts\FhirResourceContract;
use Modules\Inventory\Classes\Fhir\FhirInventoryItemTransformer;
use Modules\Inventory\Enums\InventoryItemCategory;
use Modules\Inventory\Enums\StockLocationType;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;
use Modules\Pharmacy\Enums\ControlledSchedule;
use Modules\Pharmacy\Enums\DosageForm;
use Modules\Pharmacy\Models\Medication;
use Tests\TestCase;

uses(TestCase::class);

function createInventoryItem(array $attrs = []): InventoryItem
{
    $model = new class extends InventoryItem
    {
        public $timestamps = false;
    };

    $model->setAttribute('id', '00000000-0000-0000-0000-000000000001');
    $model->setAttribute('sku', 'PAR-500');
    $model->setAttribute('name', 'Paracetamol 500mg Tablets');
    $model->setAttribute('description', 'Analgesic and antipyretic medication');
    $model->setAttribute('category', InventoryItemCategory::Supplies);
    $model->setAttribute('is_active', true);
    $model->setAttribute('medication_id', null);
    $model->setAttribute('unit_id', null);

    foreach ($attrs as $key => $value) {
        $model->setAttribute($key, $value);
    }

    return $model;
}

function createUnit(array $attrs = []): Unit
{
    $unit = new class extends Unit
    {
        public $timestamps = false;
    };

    $unit->setAttribute('id', 'unit-tab');
    $unit->setAttribute('code', '{tablet}');
    $unit->setAttribute('label', 'Tablet');
    $unit->setAttribute('category', UnitCategory::COUNT);
    $unit->setAttribute('is_fractional', false);

    foreach ($attrs as $key => $value) {
        $unit->setAttribute($key, $value);
    }

    return $unit;
}

function createMedication(array $attrs = []): Medication
{
    $med = new class extends Medication
    {
        public $timestamps = false;
    };

    $med->setAttribute('id', 'med-001');
    $med->setAttribute('generic_name', 'Paracetamol');
    $med->setAttribute('brand_name', 'Panadol');
    $med->setAttribute('strength', '500 mg');
    $med->setAttribute('dosage_form', DosageForm::TABLET);
    $med->setAttribute('controlled_schedule', null);

    foreach ($attrs as $key => $value) {
        $med->setAttribute($key, $value);
    }

    return $med;
}

function createStockBalance(array $attrs = []): StockBalance
{
    $sb = new class extends StockBalance
    {
        public $timestamps = false;
    };

    $sb->setAttribute('id', 'sb-001');
    $sb->setAttribute('inventory_item_id', '00000000-0000-0000-0000-000000000001');
    $sb->setAttribute('branch_id', 'branch-001');
    $sb->setAttribute('lot_number', 'LOT2025A');
    $sb->setAttribute('expiry_date', now()->addYear());
    $sb->setAttribute('quantity_on_hand', 100);
    $sb->setAttribute('location_type', StockLocationType::Dispensary);

    foreach ($attrs as $key => $value) {
        $sb->setAttribute($key, $value);
    }

    return $sb;
}

$transformer = new FhirInventoryItemTransformer;

test('implements FhirResourceContract', function () use ($transformer) {
    expect($transformer)->toBeInstanceOf(FhirResourceContract::class);
});

test('resourceType returns InventoryItem', function () use ($transformer) {
    expect($transformer->resourceType())->toBe('InventoryItem');
});

test('toFhir contains required fields', function () use ($transformer) {
    $model = createInventoryItem();
    $result = $transformer->toFhir($model);

    expect($result['resourceType'])->toBe('InventoryItem')
        ->and($result['id'])->toBe($model->id)
        ->and($result['identifier'][0]['value'])->toBe('PAR-500')
        ->and($result['status'])->toBe('active')
        ->and($result['name'][0]['name'])->toBe('Paracetamol 500mg Tablets');
});

test('toFhir maps status based on is_active', function () use ($transformer) {
    $active = createInventoryItem(['is_active' => true]);
    $inactive = createInventoryItem(['is_active' => false]);

    expect($transformer->toFhir($active)['status'])->toBe('active');
    expect($transformer->toFhir($inactive)['status'])->toBe('inactive');
});

test('toFhir maps category', function () use ($transformer) {
    $model = createInventoryItem(['category' => InventoryItemCategory::Consumables]);
    $result = $transformer->toFhir($model);

    expect($result['category'][0]['coding'][0]['code'])->toBe('consumables');
    expect($result['category'][0]['coding'][0]['display'])->toBe('Consumables');
});

test('toFhir maps code from sku', function () use ($transformer) {
    $model = createInventoryItem();
    $result = $transformer->toFhir($model);

    expect($result['code'][0]['coding'][0]['code'])->toBe('PAR-500');
});

test('toFhir includes description when set', function () use ($transformer) {
    $model = createInventoryItem();
    $result = $transformer->toFhir($model);

    expect($result['description']['description'])->toBe('Analgesic and antipyretic medication');
    expect($result['description']['language'])->toBe('en');
});

test('toFhir omits description when not set', function () use ($transformer) {
    $model = createInventoryItem(['description' => null]);
    $result = $transformer->toFhir($model);

    expect($result)->not->toHaveKey('description');
});

test('toFhir maps baseUnit when unit relation loaded', function () use ($transformer) {
    $unit = createUnit();
    $model = createInventoryItem();
    $model->setRelation('unit', $unit);

    $result = $transformer->toFhir($model);

    expect($result['baseUnit']['coding'][0]['code'])->toBe('{tablet}');
    expect($result['baseUnit']['coding'][0]['display'])->toBe('Tablet');
});

test('toFhir omits baseUnit when no unit', function () use ($transformer) {
    $model = createInventoryItem();
    $result = $transformer->toFhir($model);

    expect($result)->not->toHaveKey('baseUnit');
});

test('toFhir maps netContent from medication strength', function () use ($transformer) {
    $medication = createMedication(['strength' => '500 mg']);
    $model = createInventoryItem(['medication_id' => 'med-001']);
    $model->setRelation('medication', $medication);

    $result = $transformer->toFhir($model);

    expect($result['netContent']['value'])->toBe(500.0)
        ->and($result['netContent']['unit'])->toBe('mg');
});

test('toFhir maps dosage_form characteristic from medication', function () use ($transformer) {
    $medication = createMedication(['dosage_form' => DosageForm::TABLET]);
    $model = createInventoryItem(['medication_id' => 'med-001']);
    $model->setRelation('medication', $medication);

    $result = $transformer->toFhir($model);

    $dosageChar = collect($result['characteristic'])->first(
        fn ($c) => $c['characteristicType']['coding'][0]['code'] === 'dosage-form'
    );
    expect($dosageChar)->not->toBeNull()
        ->and($dosageChar['valueCodeableConcept']['coding'][0]['code'])->toBe('tablet');
});

test('toFhir maps controlled_schedule characteristic from medication', function () use ($transformer) {
    $medication = createMedication([
        'dosage_form' => DosageForm::TABLET,
        'controlled_schedule' => ControlledSchedule::SCHEDULE_4,
    ]);
    $model = createInventoryItem(['medication_id' => 'med-001']);
    $model->setRelation('medication', $medication);

    $result = $transformer->toFhir($model);

    $scheduleChar = collect($result['characteristic'])->first(
        fn ($c) => $c['characteristicType']['coding'][0]['code'] === 'controlled-schedule'
    );
    expect($scheduleChar)->not->toBeNull()
        ->and($scheduleChar['valueCodeableConcept']['coding'][0]['code'])->toBe('schedule_4');
});

test('toFhir maps productReference when medication_id set', function () use ($transformer) {
    $model = createInventoryItem(['medication_id' => 'med-001']);
    $result = $transformer->toFhir($model);

    expect($result['productReference']['reference'])->toBe('Medication/med-001');
});

test('toFhir omits productReference when no medication_id', function () use ($transformer) {
    $model = createInventoryItem();
    $result = $transformer->toFhir($model);

    expect($result)->not->toHaveKey('productReference');
});

test('toFhir maps instance from stockBalances', function () use ($transformer) {
    $expiry = now()->addYear();
    $sb = createStockBalance([
        'lot_number' => 'LOT2025A',
        'expiry_date' => $expiry,
        'branch_id' => 'branch-001',
    ]);
    $model = createInventoryItem();
    $model->setRelation('stockBalances', new Collection([$sb]));

    $result = $transformer->toFhir($model);

    expect($result['instance']['lotNumber'])->toBe('LOT2025A')
        ->and($result['instance']['expiry'])->toBe($expiry->startOfDay()->toIso8601String())
        ->and($result['instance']['location']['reference'])->toBe('Location/branch-001');
});

test('toFhir omits instance when stockBalances empty', function () use ($transformer) {
    $model = createInventoryItem();
    $model->setRelation('stockBalances', new Collection);
    $result = $transformer->toFhir($model);

    expect($result)->not->toHaveKey('instance');
});

test('toFhir skips stockBalance without lot_number or expiry', function () use ($transformer) {
    $sb = createStockBalance(['lot_number' => null, 'expiry_date' => null]);
    $model = createInventoryItem();
    $model->setRelation('stockBalances', new Collection([$sb]));
    $result = $transformer->toFhir($model);

    expect($result)->not->toHaveKey('instance');
});

test('fromFhir extracts attributes correctly', function () use ($transformer) {
    $result = $transformer->fromFhir([
        'resourceType' => 'InventoryItem',
        'identifier' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'value' => 'NEW-SKU']],
        'name' => [['nameType' => ['coding' => [['code' => 'functional-name']]], 'language' => 'en', 'name' => 'New Item']],
        'description' => ['language' => 'en', 'description' => 'A new inventory item'],
        'status' => 'active',
        'category' => [['coding' => [['code' => 'consumables']]]],
    ]);

    expect($result['sku'])->toBe('NEW-SKU')
        ->and($result['name'])->toBe('New Item')
        ->and($result['description'])->toBe('A new inventory item')
        ->and($result['is_active'])->toBeTrue()
        ->and($result['category'])->toBe('consumables');
});

test('fromFhir handles minimal resource', function () use ($transformer) {
    $result = $transformer->fromFhir([
        'resourceType' => 'InventoryItem',
        'status' => 'inactive',
        'name' => [['name' => 'Minimal Item']],
    ]);

    expect($result['name'])->toBe('Minimal Item')
        ->and($result['is_active'])->toBeFalse();
});

test('fromFhir falls back to code for sku when no identifier', function () use ($transformer) {
    $result = $transformer->fromFhir([
        'resourceType' => 'InventoryItem',
        'code' => [['coding' => [['code' => 'FALLBACK-SKU']]]],
        'name' => [['name' => 'Fallback Item']],
    ]);

    expect($result['sku'])->toBe('FALLBACK-SKU');
});

test('searchableParameters has expected keys', function () use ($transformer) {
    $params = $transformer->searchableParameters();

    expect($params)->toHaveKeys(['_id', 'identifier', 'status', 'category', 'name']);
});

test('validateBusinessRules passes with name', function () use ($transformer) {
    $errors = $transformer->validateBusinessRules([
        'name' => [['name' => 'Test Item']],
        'status' => 'active',
    ]);

    expect($errors)->toBeEmpty();
});

test('validateBusinessRules passes with code and no name', function () use ($transformer) {
    $errors = $transformer->validateBusinessRules([
        'code' => [['coding' => [['code' => 'TEST-001']]]],
        'status' => 'active',
    ]);

    expect($errors)->toBeEmpty();
});

test('validateBusinessRules fails without name or code', function () use ($transformer) {
    $errors = $transformer->validateBusinessRules(['status' => 'active']);

    expect($errors)->toHaveKey('inv-1');
});

test('validateBusinessRules fails without status', function () use ($transformer) {
    $errors = $transformer->validateBusinessRules([
        'name' => [['name' => 'Test']],
    ]);

    expect($errors)->toHaveKey('inv-2');
});
