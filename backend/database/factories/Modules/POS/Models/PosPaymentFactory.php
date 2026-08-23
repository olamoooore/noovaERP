<?php

namespace Database\Factories\Modules\POS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;

class PosPaymentFactory extends Factory
{
    protected $model = PosPayment::class;

    public function definition(): array
    {
        return [
            'pos_order_id' => PosOrder::factory(),
            'method' => 'cash',
            'amount' => 110.00,
            'received_by' => User::factory(),
        ];
    }
}
