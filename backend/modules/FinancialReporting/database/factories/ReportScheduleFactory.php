<?php

namespace Modules\FinancialReporting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FinancialReporting\Models\ReportSchedule;
use App\Models\User;

class ReportScheduleFactory extends Factory
{
    protected $model = ReportSchedule::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'report_type' => $this->faker->randomElement([
                'profit_loss',
                'balance_sheet',
                'cash_flow',
                'trial_balance',
                'general_ledger',
                'stock_valuation',
                'stock_movement',
            ]),
            'frequency' => $this->faker->randomElement([
                'daily',
                'weekly',
                'monthly',
                'quarterly',
                'yearly',
            ]),
            'parameters' => [
                'export_format' => $this->faker->randomElement(['pdf', 'excel']),
                'compare_period' => $this->faker->boolean(),
            ],
            'recipients' => [
                $this->faker->email(),
                $this->faker->email(),
            ],
            'next_run_at' => $this->faker->dateTimeBetween('now', '+1 month'),
            'last_run_at' => $this->faker->optional()->dateTimeBetween('-1 month', 'now'),
            'is_active' => $this->faker->boolean(80), // 80% active
            'created_by' => User::factory(),
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function daily(): self
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'daily',
        ]);
    }

    public function weekly(): self
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'weekly',
        ]);
    }

    public function monthly(): self
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'monthly',
        ]);
    }
}
