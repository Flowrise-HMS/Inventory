<?php

namespace Modules\Inventory\Classes\Fhir;

use Carbon\Carbon;
use DateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Unit;
use Modules\FHIR\Contracts\FhirResourceContract;
use Modules\Inventory\Enums\InventoryItemCategory;
use Modules\Inventory\Models\InventoryItem;

class FhirInventoryItemTransformer implements FhirResourceContract
{
    private const CATEGORY_SYSTEM = 'https://flowrise.app/CodeSystem/inventory-category';

    private const NAME_TYPE_SYSTEM = 'http://hl7.org/fhir/ValueSet/inventoryitem-name-type';

    private const UCUM_SYSTEM = 'http://unitsofmeasure.org';

    private const SKU_SYSTEM = 'http://terminology.hl7.org/CodeSystem/v2-0203';

    private const CHARACTERISTIC_SYSTEM = 'https://flowrise.app/CodeSystem/inventory-characteristic';

    public function resourceType(): string
    {
        return 'InventoryItem';
    }

    public function toFhir(Model $model): array
    {
        $resource = [
            'resourceType' => 'InventoryItem',
            'id' => $model->id,
            'identifier' => [
                [
                    'system' => self::SKU_SYSTEM,
                    'type' => [
                        'coding' => [
                            [
                                'system' => self::SKU_SYSTEM,
                                'code' => 'SKU',
                            ],
                        ],
                    ],
                    'value' => $model->sku,
                ],
            ],
            'status' => $model->is_active ? 'active' : 'inactive',
        ];

        if ($model->category) {
            $resource['category'] = [
                [
                    'coding' => [
                        [
                            'system' => self::CATEGORY_SYSTEM,
                            'code' => $model->category->value,
                            'display' => $model->category->getLabel(),
                        ],
                    ],
                ],
            ];
        }

        if ($model->sku) {
            $resource['code'] = [
                [
                    'coding' => [
                        [
                            'system' => self::SKU_SYSTEM,
                            'code' => $model->sku,
                        ],
                    ],
                ],
            ];
        }

        $resource['name'] = [
            [
                'nameType' => [
                    'coding' => [
                        [
                            'system' => self::NAME_TYPE_SYSTEM,
                            'code' => 'functional-name',
                        ],
                    ],
                ],
                'language' => 'en',
                'name' => $model->name,
            ],
        ];

        if ($model->description) {
            $resource['description'] = [
                'language' => 'en',
                'description' => $model->description,
            ];
        }

        if ($model->relationLoaded('unit') && $model->unit) {
            $resource['baseUnit'] = $this->buildBaseUnit($model->unit);
        }

        if ($model->relationLoaded('medication') && $model->medication) {
            $medication = $model->medication;

            if ($medication->strength) {
                $resource['netContent'] = $this->parseStrengthToQuantity($medication->strength);
            }

            $characteristics = [];

            if ($medication->dosage_form) {
                $characteristics[] = [
                    'characteristicType' => [
                        'coding' => [
                            [
                                'system' => self::CHARACTERISTIC_SYSTEM,
                                'code' => 'dosage-form',
                            ],
                        ],
                    ],
                    'valueCodeableConcept' => [
                        'coding' => [
                            [
                                'code' => $medication->dosage_form->value,
                                'display' => $medication->dosage_form->getLabel(),
                            ],
                        ],
                    ],
                ];
            }

            if ($medication->controlled_schedule) {
                $characteristics[] = [
                    'characteristicType' => [
                        'coding' => [
                            [
                                'system' => self::CHARACTERISTIC_SYSTEM,
                                'code' => 'controlled-schedule',
                            ],
                        ],
                    ],
                    'valueCodeableConcept' => [
                        'coding' => [
                            [
                                'code' => $medication->controlled_schedule->value,
                            ],
                        ],
                    ],
                ];
            }

            if (! empty($characteristics)) {
                $resource['characteristic'] = $characteristics;
            }
        }

        if ($model->medication_id) {
            $resource['productReference'] = [
                'reference' => "Medication/{$model->medication_id}",
            ];
        }

        if ($model->relationLoaded('stockBalances') && $model->stockBalances->isNotEmpty()) {
            $instances = $this->buildInstances($model->stockBalances);
            if (! empty($instances)) {
                $resource['instance'] = $instances[0];
            }
        }

        return $resource;
    }

    private function buildBaseUnit(Unit $unit): array
    {
        return [
            'coding' => [
                [
                    'system' => self::UCUM_SYSTEM,
                    'code' => $unit->code,
                    'display' => $unit->label,
                ],
            ],
        ];
    }

    private function parseStrengthToQuantity(string $strength): array
    {
        if (preg_match('/^([\d.]+)\s*([a-zA-Z%\/]+)$/', $strength, $matches)) {
            return [
                'value' => (float) $matches[1],
                'unit' => $matches[2],
                'system' => self::UCUM_SYSTEM,
                'code' => $matches[2],
            ];
        }

        return [
            'value' => 0,
            'unit' => $strength,
        ];
    }

    private function buildInstances($stockBalances): array
    {
        $instances = [];

        foreach ($stockBalances as $sb) {
            if (! $sb->lot_number && ! $sb->expiry_date) {
                continue;
            }

            $instance = [];

            if ($sb->lot_number) {
                $instance['lotNumber'] = $sb->lot_number;
            }

            if ($sb->expiry_date) {
                $instance['expiry'] = $sb->expiry_date instanceof DateTime
                    ? $sb->expiry_date->toIso8601String()
                    : Carbon::parse($sb->expiry_date)->toIso8601String();
            }

            if ($sb->branch_id) {
                $instance['location'] = [
                    'reference' => "Location/{$sb->branch_id}",
                ];
            }

            $instances[] = $instance;
        }

        return $instances;
    }

    public function fromFhir(array $fhirResource): array
    {
        $attrs = [];

        if (isset($fhirResource['identifier'][0]['value'])) {
            $attrs['sku'] = $fhirResource['identifier'][0]['value'];
        } elseif (isset($fhirResource['code'][0]['coding'][0]['code'])) {
            $attrs['sku'] = $fhirResource['code'][0]['coding'][0]['code'];
        }

        if (isset($fhirResource['name'][0]['name'])) {
            $attrs['name'] = $fhirResource['name'][0]['name'];
        }

        if (isset($fhirResource['description']['description'])) {
            $attrs['description'] = $fhirResource['description']['description'];
        }

        if (isset($fhirResource['status'])) {
            $attrs['is_active'] = $fhirResource['status'] === 'active';
        }

        if (isset($fhirResource['category'][0]['coding'][0]['code'])) {
            $code = $fhirResource['category'][0]['coding'][0]['code'];
            if (in_array($code, InventoryItemCategory::values(), true)) {
                $attrs['category'] = $code;
            }
        }

        return $attrs;
    }

    public function findById(string $id): ?Model
    {
        return InventoryItem::with([
            'unit',
            'medication',
            'stockBalances',
        ])->find($id);
    }

    public function query(): Builder
    {
        return InventoryItem::with([
            'unit',
            'medication',
            'stockBalances',
        ]);
    }

    public function searchableParameters(): array
    {
        return [
            '_id' => ['column' => 'id'],
            'identifier' => ['column' => 'sku'],
            'status' => ['column' => 'is_active'],
            'category' => ['column' => 'category'],
            'name' => ['column' => 'name'],
        ];
    }

    public function validateBusinessRules(array $fhirResource): array
    {
        $errors = [];

        $hasName = isset($fhirResource['name'][0]['name']) && ! empty($fhirResource['name'][0]['name']);
        $hasCode = isset($fhirResource['code'][0]['coding'][0]['code']) && ! empty($fhirResource['code'][0]['coding'][0]['code']);

        if (! $hasName && ! $hasCode) {
            $errors['inv-1'] = 'InventoryItem SHALL have at least a name or a code.';
        }

        if (! isset($fhirResource['status'])) {
            $errors['inv-2'] = 'InventoryItem SHALL have a status.';
        }

        return $errors;
    }
}
