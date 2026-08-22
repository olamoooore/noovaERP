<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company . ' Warehouse',
            'code' => strtoupper($this->faker->bothify('WH-###')),
            'is_active' => true,
        ];
    }
}
