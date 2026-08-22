<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillPaymentFactory extends Factory
{
    protected $model = BillPayment::class;

    public function definition(): array
    {
        return [
            'payment_number' => strtoupper($this->faker->bothify('PAY-####')),
            'vendor_id' => Vendor::factory(),
            'bill_id' => Bill::factory(),
            'payment_date' => now(),
            'amount' => 500.00,
            'payment_method' => 'bank',
            'created_by' => User::factory(),
            'payment_status' => 'completed',
        ];
    }
}
