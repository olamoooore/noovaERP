<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'payment_number' => 'PAY-'.date('Y').'-'.str_pad((string) (Payment::count() + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory(),
            'invoice_id' => Invoice::factory(),
            'payment_date' => now()->toDateString(),
            'amount' => $this->faker->randomFloat(2, 100, 10000),
            'method' => $this->faker->randomElement(['cash', 'check', 'bank_transfer', 'card']),
            'reference' => $this->faker->optional()->word(),
            'notes' => $this->faker->optional()->sentence(),
            'received_by' => 1,
            'payment_status' => 'recorded',
            'version_number' => 1,
            'is_active_version' => true,
        ];
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'applied',
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'refunded',
        ]);
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'reversed',
        ]);
    }

    public function unapplied(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'unapplied',
        ]);
    }
}
