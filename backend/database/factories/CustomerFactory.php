<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'type' => 'individual',
            'name' => $this->faker->company(),
            'code' => 'CUST-'.str_pad((string) (Customer::count() + 1), 4, '0', STR_PAD_LEFT),
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'company' => $this->faker->optional()->company(),
            'billing_address' => $this->faker->optional()->address(),
            'shipping_address' => $this->faker->optional()->address(),
            'credit_limit' => null,
            'payment_terms' => 30,
            'total_outstanding' => 0,
            'is_active' => true,
            'marketing_opt_in' => false,
            'customer_since' => now()->toDateString(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
