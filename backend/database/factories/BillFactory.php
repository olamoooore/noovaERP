<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillFactory extends Factory
{
    protected $model = Bill::class;

    public function definition(): array
    {
        return [
            'bill_number' => 'BILL-'.date('Y').'-'.str_pad((string) (Bill::count() + 1), 4, '0', STR_PAD_LEFT),
            'vendor_id' => Vendor::factory(),
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'settlement_status' => 'unpaid',
            'reconciliation_status' => 'unreconciled',
            'bill_type' => 'procurement',
            'subtotal' => $this->faker->randomFloat(2, 100, 10000),
            'tax_amount' => $this->faker->randomFloat(2, 0, 1000),
            'discount_amount' => 0,
            'total' => $this->faker->randomFloat(2, 100, 10000),
            'paid_amount' => 0,
            'balance' => $this->faker->randomFloat(2, 100, 10000),
            'notes' => $this->faker->optional()->sentence(),
            'terms' => $this->faker->optional()->sentence(),
            'created_by' => \App\Models\User::factory()->create()->id,
            'version_number' => 1,
            'is_active_version' => true,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'unpaid',
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'paid',
            'paid_amount' => $attributes['total'] ?? 500,
            'balance' => 0,
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'bill_type' => 'expense',
        ]);
    }
}
