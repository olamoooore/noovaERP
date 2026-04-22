<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'code' => 'VEND-' . str_pad((string) (Vendor::count() + 1), 4, '0', STR_PAD_LEFT),
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