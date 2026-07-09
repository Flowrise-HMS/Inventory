<?php

namespace Modules\Inventory\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Department;
use Modules\Inventory\Models\Requisition;

class RequisitionFactory extends Factory
{
    protected $model = Requisition::class;

    private static int $reqCounter = 0;

    public function definition(): array
    {
        static::$reqCounter++;

        return [
            'requisition_number' => 'REQ-'.str_pad((string) static::$reqCounter, 4, '0', STR_PAD_LEFT),
            'requestor_id' => User::factory(),
            'department_id' => Department::factory(),
            'branch_id' => Branch::factory(),
            'status' => 'pending',
        ];
    }
}
