<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'code' => 'CUST-' . str_pad((string) (Customer::count() + 1), 4, '0', STR_PAD_LEFT),
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'address' => $this->faker->optional()->address(),
            'city' => $this->faker->optional()->city(),
            'state' => $this->faker->optional()->state(),
            'country' => $this->faker->optional()->country(),
            'postal_code' => $this->faker->optional()->postcode(),
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}