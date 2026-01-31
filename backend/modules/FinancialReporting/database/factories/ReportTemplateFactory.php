<?php

namespace Modules\FinancialReporting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FinancialReporting\Models\ReportTemplate;
use App\Models\User;

class ReportTemplateFactory extends Factory
{
    protected $model = ReportTemplate::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->sentence(),
            'report_type' => $this->faker->randomElement([
                'profit_loss',
                'balance_sheet',
                'cash_flow',
                'trial_balance',
                'general_ledger',
                'stock_valuation',
                'stock_movement',
                'sales_by_product',
                'sales_by_customer',
                'expenses_by_category',
            ]),
            'configuration' => [
                'columns' => $this->faker->randomElements(
                    ['account_name', 'debit', 'credit', 'balance', 'amount'],
                    $this->faker->numberBetween(2, 4)
                ),
                'filters' => [
                    'compare_period' => $this->faker->boolean(),
                ],
                'grouping' => $this->faker->optional()->randomElements(
                    ['account_type', 'department', 'category'],
                    1
                ),
                'sorting' => [
                    'field' => 'account_name',
                    'direction' => $this->faker->randomElement(['asc', 'desc']),
                ],
            ],
            'is_system' => false,
            'created_by' => User::factory(),
        ];
    }

    public function system(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    public function shared(): self
    {
        return $this->state(function (array $attributes) {
            $config = $attributes['configuration'] ?? [];
            $config['shared'] = true;
            $config['shared_by'] = $attributes['created_by'];
            $config['shared_at'] = now()->toIso8601String();
            
            return ['configuration' => $config];
        });
    }

    public function profitLoss(): self
    {
        return $this->state(fn (array $attributes) => [
            'report_type' => 'profit_loss',
        ]);
    }

    public function balanceSheet(): self
    {
        return $this->state(fn (array $attributes) => [
            'report_type' => 'balance_sheet',
        ]);
    }
}
