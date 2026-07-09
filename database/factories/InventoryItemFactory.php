<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Unit;
use Modules\Inventory\Enums\InventoryItemCategory;
use Modules\Inventory\Models\InventoryItem;

class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'category' => $this->faker->randomElement(InventoryItemCategory::values()),
            'unit_id' => Unit::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
