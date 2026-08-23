<?php

namespace Database\Factories\Modules\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\PaymentGateway\Models\PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = \Modules\PaymentGateway\Models\PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'payment_gateway_id' => \Modules\PaymentGateway\Models\PaymentGateway::factory(),
            'transaction_id' => 'txn_'.$this->faker->unique()->uuid(),
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_method' => 'card',
            'refunded_amount' => 0,
            'webhook_attempts' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error_message' => $this->faker->sentence(),
        ]);
    }
}
