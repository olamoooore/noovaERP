<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'company' => $this->faker->company(),
            'tax_number' => $this->faker->optional()->bothify('TAX-####'),
            'address' => $this->faker->optional()->address(),
            'contact_person' => $this->faker->optional()->name(),
            'website' => $this->faker->optional()->url(),
            'payment_terms' => 30,
            'total_spending' => 0,
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}