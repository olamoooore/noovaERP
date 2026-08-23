<?php

namespace Database\Factories\Modules\POS\Models;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;

class PosOrderFactory extends Factory
{
    protected $model = PosOrder::class;

    public function definition(): array
    {
        return [
            'external_id' => (string) Str::uuid(),
            'order_number' => strtoupper($this->faker->bothify('ORD-####')),
            'session_id' => PosSession::factory(),
            'terminal_id' => PosTerminal::factory(),
            'customer_id' => Customer::factory(),
            'status' => 'completed',
            'subtotal' => 100.00,
            'tax_amount' => 10.00,
            'total' => 110.00,
            'currency' => 'SGD',
            'created_by' => User::factory(),
            'payment_state' => 'paid',
        ];
    }
}
