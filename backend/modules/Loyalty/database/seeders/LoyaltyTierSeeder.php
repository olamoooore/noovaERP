<?php

namespace Modules\Loyalty\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTier;

class LoyaltyTierSeeder extends Seeder
{
    public function run(): void
    {
        $hasAnotherActiveProgram = LoyaltyProgram::query()
            ->where('name', '!=', 'Default Loyalty Program')
            ->where('is_active', true)
            ->exists();

        $program = LoyaltyProgram::updateOrCreate(
            ['name' => 'Default Loyalty Program'],
            [
                'description' => 'Earn points with every purchase and unlock exclusive rewards',
                'points_per_currency' => 1.00,
                'currency_per_point' => 0.01,
                'min_redemption_points' => 100,
                'max_redemption_percent' => 50.00,
                'points_expiry_months' => 12,
                'is_active' => ! $hasAnotherActiveProgram,
            ]
        );

        $tiers = [
            [
                'name' => 'Bronze',
                'min_points' => 0,
                'max_points' => 999,
                'earning_multiplier' => 1.0,
                'discount_percent' => 0.0,
                'benefits' => [
                    'Basic rewards access',
                    'Birthday bonus points',
                ],
                'color' => '#CD7F32',
                'sort_order' => 1,
            ],
            [
                'name' => 'Silver',
                'min_points' => 1000,
                'max_points' => 4999,
                'earning_multiplier' => 1.2,
                'discount_percent' => 5.0,
                'benefits' => [
                    'All Bronze benefits',
                    '1.2x points multiplier',
                    '5% discount on purchases',
                    'Early access to sales',
                ],
                'color' => '#C0C0C0',
                'sort_order' => 2,
            ],
            [
                'name' => 'Gold',
                'min_points' => 5000,
                'max_points' => 9999,
                'earning_multiplier' => 1.5,
                'discount_percent' => 10.0,
                'benefits' => [
                    'All Silver benefits',
                    '1.5x points multiplier',
                    '10% discount on purchases',
                    'Free shipping',
                    'Priority customer support',
                ],
                'color' => '#FFD700',
                'sort_order' => 3,
            ],
            [
                'name' => 'Platinum',
                'min_points' => 10000,
                'max_points' => null,
                'earning_multiplier' => 2.0,
                'discount_percent' => 15.0,
                'benefits' => [
                    'All Gold benefits',
                    '2x points multiplier',
                    '15% discount on purchases',
                    'Exclusive VIP events',
                    'Personal account manager',
                ],
                'color' => '#E5E4E2',
                'sort_order' => 4,
            ],
        ];

        foreach ($tiers as $tierData) {
            LoyaltyTier::updateOrCreate(
                [
                    'loyalty_program_id' => $program->id,
                    'name' => $tierData['name'],
                ],
                $tierData
            );
        }

        $preferredActiveProgramId = LoyaltyProgram::query()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN name = 'Default Loyalty Program' THEN 1 ELSE 0 END")
            ->orderBy('id')
            ->value('id') ?? $program->id;

        LoyaltyProgram::query()
            ->whereKeyNot($preferredActiveProgramId)
            ->update(['is_active' => false]);

        LoyaltyProgram::query()
            ->whereKey($preferredActiveProgramId)
            ->update(['is_active' => true]);
    }
}
