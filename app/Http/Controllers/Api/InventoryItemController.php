<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\InventoryItemCategory;
use Modules\Inventory\Models\InventoryItem;

/**
 * @group Inventory
 *
 * The facility-wide item catalogue. Deleting deactivates the item (ledger history is kept).
 */
class InventoryItemController extends InventoryApiController
{
    protected string $branchScope = 'none';

    protected string $defaultSort = 'name';

    public static function model(): string
    {
        return InventoryItem::class;
    }

    public function fields(): array
    {
        return ['id', 'name', 'sku', 'description', 'category', 'medication_id', 'unit_id', 'is_active', 'created_at', 'updated_at'];
    }

    public function filters(): array
    {
        return ['category' => 'category', 'is_active' => 'is_active', 'medication_id' => 'medication_id'];
    }

    public function searchable(): array
    {
        return ['name', 'sku'];
    }

    public function sortable(): array
    {
        return ['name', 'sku', 'created_at'];
    }

    public function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('inventory_items', 'sku')],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::enum(InventoryItemCategory::class)],
            'medication_id' => ['nullable', 'uuid'],
            'unit_id' => ['required', 'uuid', 'exists:units,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function updateRules(?Model $record = null): array
    {
        return collect(parent::updateRules($record))
            ->put('sku', ['sometimes', 'string', 'max:100', Rule::unique('inventory_items', 'sku')->ignore($record?->getKey())])
            ->all();
    }

    protected function deleteRecord(Model $record): void
    {
        $record->update(['is_active' => false]);
    }
}
