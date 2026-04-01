<?php

namespace Modules\FinancialReporting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FinancialReporting\Models\ReportCache;
use Carbon\Carbon;

class ReportCacheFactory extends Factory
{
    protected $model = ReportCache::class;

    public function definition(): array
    {
        return [
            'cache_key' => 'report_' . $this->faker->unique()->uuid(),
            'report_type' => $this->faker->randomElement([
                'profit_loss',
                'balance_sheet',
                'cash_flow',
                'trial_balance',
                'stock_valuation',
            ]),
            'filters' => [
                'start_date' => $this->faker->date(),
                'end_date' => $this->faker->date(),
            ],
            'data' => [
                'revenue' => $this->faker->randomFloat(2, 10000, 100000),
                'expenses' => $this->faker->randomFloat(2, 5000, 50000),
            ],
            'expires_at' => Carbon::now()->addHours($this->faker->numberBetween(1, 24)),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => Carbon::now()->subHours($this->faker->numberBetween(1, 24)),
        ]);
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
