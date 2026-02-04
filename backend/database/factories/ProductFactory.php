<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Product;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('PROD-####'),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'unit_cost' => fake()->randomFloat(2, 10, 1000),
            'unit_price' => fake()->randomFloat(2, 20, 2000),
            'quantity_on_hand' => fake()->numberBetween(0, 100),
            'is_active' => true,
            'created_by' => 1,
        ];
    }
}
