<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Models\Supplier;

/**
 * @group Inventory
 *
 * Suppliers. Deleting deactivates the supplier.
 */
class SupplierController extends InventoryApiController
{
    protected string $branchScope = 'none';

    protected string $defaultSort = 'name';

    public static function model(): string
    {
        return Supplier::class;
    }

    public function fields(): array
    {
        return ['id', 'name', 'contact_person', 'email', 'phone', 'is_active', 'created_at', 'updated_at'];
    }

    public function filters(): array
    {
        return ['is_active' => 'is_active'];
    }

    public function searchable(): array
    {
        return ['name', 'contact_person'];
    }

    public function sortable(): array
    {
        return ['name', 'created_at'];
    }

    public function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function deleteRecord(Model $record): void
    {
        $record->update(['is_active' => false]);
    }
}
