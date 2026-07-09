<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Branch;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\Supplier;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    private static int $poCounter = 0;

    public function definition(): array
    {
        static::$poCounter++;

        return [
            'supplier_id' => Supplier::factory(),
            'branch_id' => Branch::factory(),
            'po_number' => 'PO-'.str_pad((string) static::$poCounter, 4, '0', STR_PAD_LEFT),
            'status' => 'draft',
        ];
    }
}
