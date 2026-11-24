<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Modules\Inventory\Models\InventoryTransaction;

/**
 * @group Inventory
 *
 * The stock ledger (read-only).
 */
class InventoryTransactionController extends InventoryApiController
{
    public static function model(): string
    {
        return InventoryTransaction::class;
    }

    public static function label(): string
    {
        return 'stock transaction';
    }

    public function fields(): array
    {
        return ['id', 'inventory_item_id', 'delta', 'quantity_after', 'transaction_type', 'lot_number', 'expiry_date',
            'from_location_type', 'from_location_id', 'to_location_type', 'to_location_id', 'reference_type', 'reference_id',
            'performed_by', 'created_at'];
    }

    public function filters(): array
    {
        return ['inventory_item_id' => 'inventory_item_id', 'transaction_type' => 'transaction_type', 'reference_id' => 'reference_id'];
    }
}
