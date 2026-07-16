<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'sku' => fake()->unique()->bothify('PROD-####'),
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->sentence(),
            'cost' => fake()->randomFloat(2, 10, 1000),
            'price' => fake()->randomFloat(2, 20, 2000),
            'stock_quantity' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
