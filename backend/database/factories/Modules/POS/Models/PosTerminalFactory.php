<?php

namespace Database\Factories\Modules\POS\Models;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\POS\Models\PosTerminal;

class PosTerminalFactory extends Factory
{
    protected $model = PosTerminal::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word . ' Terminal',
            'code' => strtoupper($this->faker->bothify('POS-###')),
            'warehouse_id' => Warehouse::factory(),
            'is_active' => true,
            'settings' => [],
        ];
    }
}
