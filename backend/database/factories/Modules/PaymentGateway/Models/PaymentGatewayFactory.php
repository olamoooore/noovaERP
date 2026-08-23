<?php

namespace Database\Factories\Modules\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\PaymentGateway\Models\PaymentGateway>
 */
class PaymentGatewayFactory extends Factory
{
    protected $model = \Modules\PaymentGateway\Models\PaymentGateway::class;

    public function definition(): array
    {
        return [
            'gateway_name' => $this->faker->unique()->company().' Gateway',
            'gateway_type' => 'online',
            'publishable_key' => 'pk_test_'.$this->faker->uuid(),
            'api_key' => 'ak_test_'.$this->faker->uuid(),
            'api_secret' => 'sk_test_'.$this->faker->uuid(),
            'webhook_secret' => 'whsec_'.$this->faker->uuid(),
            'is_active' => true,
            'is_test_mode' => true,
            'config' => ['region' => 'us'],
        ];
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'gateway_type' => 'offline',
            'gateway_name' => $this->faker->unique()->company().' Offline',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function testMode(): static
    {
        return $this->state(fn () => ['is_test_mode' => true]);
    }
}
