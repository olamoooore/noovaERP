<?php

namespace Database\Factories;

use App\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'parent_id' => null,
            'is_active' => true,
        ];
    }
}
