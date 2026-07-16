<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-'.date('Y').'-'.str_pad((string) (Invoice::count() + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'settlement_status' => 'unpaid',
            'reconciliation_status' => 'unreconciled',
            'subtotal' => $this->faker->randomFloat(2, 100, 10000),
            'tax_amount' => $this->faker->randomFloat(2, 0, 1000),
            'discount_amount' => 0,
            'total' => $this->faker->randomFloat(2, 100, 10000),
            'paid_amount' => 0,
            'balance' => $this->faker->randomFloat(2, 100, 10000),
            'notes' => $this->faker->optional()->sentence(),
            'terms' => $this->faker->optional()->sentence(),
            'created_by' => 1,
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

    public function partiallyPaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'partial',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'partially_paid',
            'paid_amount' => ($attributes['total'] ?? 500) / 2,
            'balance' => ($attributes['total'] ?? 500) / 2,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'voided',
            'lifecycle_status' => 'voided',
            'is_active_version' => false,
        ]);
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'reversed',
            'lifecycle_status' => 'reversed',
            'is_active_version' => false,
        ]);
    }
}
