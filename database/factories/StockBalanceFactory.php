<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Branch;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\StockBalance;

class StockBalanceFactory extends Factory
{
    protected $model = StockBalance::class;

    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'branch_id' => Branch::factory(),
            'location_type' => 'dispensary',
            'quantity_on_hand' => 100,
        ];
    }
}
