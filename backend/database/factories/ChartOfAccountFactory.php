<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\ChartOfAccount;

class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['asset', 'liability', 'equity', 'revenue', 'expense']);
        $subtypeMap = [
            'asset' => 'current_asset',
            'liability' => 'current_liability',
            'equity' => 'equity',
            'revenue' => 'operating_revenue',
            'expense' => 'operating_expense',
        ];

        return [
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->words(3, true),
            'type' => $type,
            'subtype' => $subtypeMap[$type],
            'account_type' => $type,
            'parent_id' => null,
            'is_active' => true,
        ];
    }

    /**
     * Configure the factory to sync type/subtype when account_type is overridden.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ChartOfAccount $account) {
            $subtypeMap = [
                'asset' => 'current_asset',
                'liability' => 'current_liability',
                'equity' => 'equity',
                'revenue' => 'operating_revenue',
                'expense' => 'operating_expense',
            ];

            // Keep type and subtype in sync with account_type when overridden
            if ($account->account_type && isset($subtypeMap[$account->account_type])) {
                $account->type = $account->account_type;
                $account->subtype = $subtypeMap[$account->account_type];
            }
        });
    }
}
