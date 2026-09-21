<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Branch;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Models\StockTransfer;

class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    private static int $transferCounter = 0;

    public function definition(): array
    {
        static::$transferCounter++;

        return [
            'transfer_number' => 'TRF-'.str_pad((string) static::$transferCounter, 4, '0', STR_PAD_LEFT),
            'from_branch_id' => Branch::factory(),
            'to_branch_id' => Branch::factory(),
            'status' => StockTransferStatus::Draft,
        ];
    }
}
