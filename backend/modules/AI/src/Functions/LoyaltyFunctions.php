<?php

namespace Modules\AI\Functions;

use Illuminate\Support\Facades\Log;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyReward;

/**
 * Loyalty Functions
 *
 * AI-callable functions for loyalty program queries
 */
class LoyaltyFunctions
{
    /**
     * Get customer loyalty points balance and tier information
     *
     * @param  array  $args  Arguments with optional customer_name
     * @param  int  $customerId  Customer ID from conversation context (fallback)
     * @return array Loyalty information
     */
    public static function getPoints(array $args, int $customerId): array
    {
        try {
            // If customer_name is provided, search for that customer
            if (! empty($args['customer_name'])) {
                $customer = \App\Models\Customer::where('name', 'like', '%'.$args['customer_name'].'%')
                    ->first();

                if (! $customer) {
                    return [
                        'success' => false,
                        'message' => "Customer '{$args['customer_name']}' not found",
                    ];
                }

                $customerId = $customer->id;
            }

            $loyalty = CustomerLoyalty::with(['tier', 'program'])
                ->where('customer_id', $customerId)
                ->first();

            if (! $loyalty) {
                return [
                    'success' => false,
                    'enrolled' => false,
                    'message' => 'Customer is not enrolled in loyalty program',
                ];
            }

            return [
                'success' => true,
                'enrolled' => true,
                'customer_id' => $customerId,
                'url' => '/loyalty/customers/'.$customerId,
                'customer_name' => $customer->name ?? null,
                'points_balance' => $loyalty->current_points,
                'lifetime_points' => $loyalty->lifetime_points,
                'tier' => [
                    'name' => $loyalty->tier->name,
                    'min_points' => $loyalty->tier->min_points,
                    'discount_percentage' => $loyalty->tier->discount_percentage,
                    'points_multiplier' => $loyalty->tier->points_multiplier,
                ],
                'program' => [
                    'name' => $loyalty->program->name,
                    'points_per_currency' => $loyalty->program->points_per_currency,
                    'currency_per_point' => $loyalty->program->currency_per_point,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('LoyaltyFunctions::getPoints failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve loyalty points',
            ];
        }
    }

    /**
     * Get available loyalty rewards that customer can redeem
     *
     * @param  array  $args  Arguments with optional customer_name
     * @param  int  $customerId  Customer ID from conversation context (fallback)
     * @return array Available rewards
     */
    public static function getRewards(array $args, int $customerId): array
    {
        try {
            // If customer_name is provided, search for that customer
            if (! empty($args['customer_name'])) {
                $customer = \App\Models\Customer::where('name', 'like', '%'.$args['customer_name'].'%')
                    ->first();

                if (! $customer) {
                    return [
                        'success' => false,
                        'message' => "Customer '{$args['customer_name']}' not found",
                    ];
                }

                $customerId = $customer->id;
            }

            $loyalty = CustomerLoyalty::where('customer_id', $customerId)->first();

            if (! $loyalty) {
                return [
                    'success' => false,
                    'enrolled' => false,
                    'message' => 'Customer is not enrolled in loyalty program',
                ];
            }

            // Get rewards customer can afford
            $affordableRewards = LoyaltyReward::where('is_active', true)
                ->where('loyalty_program_id', $loyalty->loyalty_program_id)
                ->where('points_cost', '<=', $loyalty->current_points)
                ->orderBy('points_cost', 'asc')
                ->take(5)
                ->get(['id', 'name', 'description', 'points_cost', 'stock_quantity', 'image_url'])
                ->toArray();

            // Get all rewards (for reference)
            $allRewards = LoyaltyReward::where('is_active', true)
                ->where('loyalty_program_id', $loyalty->loyalty_program_id)
                ->orderBy('points_cost', 'asc')
                ->take(10)
                ->get(['id', 'name', 'points_cost', 'description'])
                ->toArray();

            return [
                'success' => true,
                'enrolled' => true,
                'customer_name' => $customer->name ?? null,
                'current_points' => $loyalty->current_points,
                'affordable_rewards' => $affordableRewards,
                'all_rewards' => $allRewards,
                'affordable_count' => count($affordableRewards),
            ];

        } catch (\Exception $e) {
            Log::error('LoyaltyFunctions::getRewards failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve rewards',
            ];
        }
    }

    /**
     * Check customer loyalty points - Simplified version for chatbot
     *
     * @param  array  $args  Arguments
     * @return array Points balance and tier info
     */
    public static function checkLoyaltyPoints(array $args): array
    {
        $customerId = $args['customer_id'] ?? null;

        if (! $customerId) {
            return ['success' => false, 'message' => 'Customer ID required'];
        }

        $loyalty = CustomerLoyalty::with(['tier', 'program'])
            ->where('customer_id', $customerId)
            ->first();

        if (! $loyalty) {
            return [
                'success' => false,
                'message' => 'Not enrolled in loyalty program',
            ];
        }

        return [
            'success' => true,
            'points' => $loyalty->current_points,
            'lifetime_points' => $loyalty->lifetime_points,
            'tier' => $loyalty->tier->name,
            'tier_multiplier' => $loyalty->tier->points_multiplier,
            'tier_discount' => $loyalty->tier->discount_percentage,
        ];
    }

    /**
     * Get loyalty program details and how to join
     *
     * @param  array  $args  Arguments
     * @return array Program information
     */
    public static function getLoyaltyProgramDetails(array $args): array
    {
        try {
            $customerId = $args['customer_id'] ?? null;
            $program = \Modules\Loyalty\Models\LoyaltyProgram::first();

            if (! $program) {
                return ['success' => false, 'message' => 'No loyalty program available'];
            }

            return [
                'success' => true,
                'program' => [
                    'name' => $program->name,
                    'description' => $program->description,
                    'points_per_currency' => $program->points_per_currency,
                    'currency_per_point' => $program->currency_per_point,
                    'how_to_join' => 'Make your first purchase and you\'ll be automatically enrolled!',
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get customer tier information with benefits
     *
     * @param  array  $args  Arguments
     * @return array Tier info and benefits
     */
    public static function getCustomerTierInfo(array $args): array
    {
        try {
            $customerId = $args['customer_id'] ?? null;

            if (! $customerId) {
                return ['success' => false, 'message' => 'Customer ID required'];
            }

            $loyalty = CustomerLoyalty::with(['tier', 'program'])->where('customer_id', $customerId)->first();

            if (! $loyalty) {
                return ['success' => false, 'message' => 'Not enrolled'];
            }

            $currentTier = $loyalty->tier;

            // Get next tier
            $nextTier = \Modules\Loyalty\Models\LoyaltyTier::where('loyalty_program_id', $loyalty->loyalty_program_id)
                ->where('min_points', '>', $currentTier->min_points)
                ->orderBy('min_points', 'asc')
                ->first();

            $response = [
                'success' => true,
                'current_points' => $loyalty->current_points,
                'current_tier' => [
                    'name' => $currentTier->name,
                    'min_points' => $currentTier->min_points,
                    'max_points' => $currentTier->max_points,
                    'multiplier' => $currentTier->points_multiplier,
                    'discount' => $currentTier->discount_percentage,
                    'benefits' => $currentTier->benefits ?? [],
                ],
            ];

            if ($nextTier) {
                $response['next_tier'] = [
                    'name' => $nextTier->name,
                    'min_points' => $nextTier->min_points,
                ];
                $response['points_to_next_tier'] = $nextTier->min_points - $loyalty->current_points;
            }

            return $response;
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List available rewards customer can redeem
     *
     * @param  array  $args  Arguments
     * @return array Available rewards
     */
    public static function listAvailableRewards(array $args): array
    {
        try {
            $customerId = $args['customer_id'] ?? null;

            if (! $customerId) {
                return ['success' => false, 'message' => 'Customer ID required'];
            }

            $loyalty = CustomerLoyalty::where('customer_id', $customerId)->first();

            if (! $loyalty) {
                return ['success' => false, 'message' => 'Not enrolled'];
            }

            $rewards = LoyaltyReward::where('is_active', true)
                ->where('loyalty_program_id', $loyalty->loyalty_program_id)
                ->where('points_cost', '<=', $loyalty->current_points)
                ->orderBy('points_cost', 'asc')
                ->get(['name', 'description', 'points_cost', 'stock_quantity'])
                ->toArray();

            return [
                'success' => true,
                'current_points' => $loyalty->current_points,
                'rewards' => $rewards,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check redemption history
     *
     * @param  array  $args  Arguments
     * @return array Redemption history
     */
    public static function checkRedemptionHistory(array $args): array
    {
        try {
            $customerId = $args['customer_id'] ?? null;

            if (! $customerId) {
                return ['success' => false, 'message' => 'Customer ID required'];
            }

            $redemptions = \Modules\Loyalty\Models\RewardRedemption::where('customer_id', $customerId)
                ->with('reward')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($redemption) {
                    return [
                        'reward_name' => $redemption->reward->name ?? 'Unknown',
                        'points_spent' => $redemption->points_spent,
                        'status' => $redemption->status,
                        'date' => $redemption->created_at->format('Y-m-d'),
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'redemptions' => $redemptions,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Calculate points customer will earn from a purchase
     *
     * @param  array  $args  Arguments with purchase_amount
     * @return array Points calculation
     */
    public static function calculatePointsForPurchase(array $args): array
    {
        try {
            $customerId = $args['customer_id'] ?? null;
            $purchaseAmount = $args['purchase_amount'] ?? 0;

            if (! $customerId || ! $purchaseAmount) {
                return ['success' => false, 'message' => 'Customer ID and purchase amount required'];
            }

            $loyalty = CustomerLoyalty::with(['tier', 'program'])
                ->where('customer_id', $customerId)
                ->first();

            if (! $loyalty) {
                // Default calculation without tier benefits
                $basePoints = round($purchaseAmount);

                return [
                    'success' => true,
                    'purchase_amount' => $purchaseAmount,
                    'base_points' => $basePoints,
                    'tier_multiplier' => 1.0,
                    'points_earned' => $basePoints,
                    'message' => 'Join our loyalty program to earn more points!',
                ];
            }

            $basePoints = round($purchaseAmount * $loyalty->program->points_per_currency);
            $multiplier = $loyalty->tier->points_multiplier;
            $totalPoints = round($basePoints * $multiplier);

            return [
                'success' => true,
                'purchase_amount' => $purchaseAmount,
                'base_points' => $basePoints,
                'tier' => $loyalty->tier->name,
                'tier_multiplier' => $multiplier,
                'points_earned' => $totalPoints,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get all tier benefits for the program
     *
     * @param  array  $args  Arguments
     * @return array All tiers with benefits
     */
    public static function getTierBenefits(array $args): array
    {
        try {
            $customerId = $args['customer_id'] ?? null;

            // Get program (assume first one or from customer)
            $programId = null;
            if ($customerId) {
                $loyalty = CustomerLoyalty::where('customer_id', $customerId)->first();
                $programId = $loyalty->loyalty_program_id ?? null;
            }

            if (! $programId) {
                $program = \Modules\Loyalty\Models\LoyaltyProgram::first();
                $programId = $program->id ?? null;
            }

            if (! $programId) {
                return ['success' => false, 'message' => 'No loyalty program found'];
            }

            $tiers = \Modules\Loyalty\Models\LoyaltyTier::where('loyalty_program_id', $programId)
                ->orderBy('min_points', 'asc')
                ->get()
                ->map(function ($tier) {
                    return [
                        'name' => $tier->name,
                        'min_points' => $tier->min_points,
                        'max_points' => $tier->max_points,
                        'multiplier' => $tier->points_multiplier,
                        'discount_percentage' => $tier->discount_percentage,
                        'benefits' => $tier->benefits ?? [],
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'tiers' => $tiers,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check progress to next tier
     *
     * @param  array  $args  Arguments
     * @return array Next tier progress
     */
    public static function checkNextTierProgress(array $args): array
    {
        try {
            $customerId = $args['customer_id'] ?? null;

            if (! $customerId) {
                return ['success' => false, 'message' => 'Customer ID required'];
            }

            $loyalty = CustomerLoyalty::with('tier')->where('customer_id', $customerId)->first();

            if (! $loyalty) {
                return ['success' => false, 'message' => 'Not enrolled'];
            }

            $currentTier = $loyalty->tier;
            $nextTier = \Modules\Loyalty\Models\LoyaltyTier::where('loyalty_program_id', $loyalty->loyalty_program_id)
                ->where('min_points', '>', $currentTier->min_points)
                ->orderBy('min_points', 'asc')
                ->first();

            if (! $nextTier) {
                return [
                    'success' => true,
                    'current_tier' => $currentTier->name,
                    'is_max_tier' => true,
                    'message' => 'You are at the highest tier!',
                ];
            }

            $pointsNeeded = $nextTier->min_points - $loyalty->current_points;
            $progressPercentage = round(($loyalty->current_points / $nextTier->min_points) * 100, 2);

            return [
                'success' => true,
                'current_tier' => $currentTier->name,
                'current_points' => $loyalty->current_points,
                'next_tier' => [
                    'name' => $nextTier->name,
                    'min_points' => $nextTier->min_points,
                ],
                'points_needed' => $pointsNeeded,
                'progress_percentage' => $progressPercentage,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List all available loyalty programs
     */
    public static function listLoyaltyPrograms(array $args): array
    {
        try {
            $programs = \Modules\Loyalty\Models\LoyaltyProgram::with('tiers')
                ->where('is_active', true)
                ->get()
                ->map(function ($program) {
                    return [
                        'id' => $program->id,
                        'name' => $program->name,
                        'description' => $program->description,
                        'is_active' => $program->is_active,
                        'points_per_currency' => $program->points_per_currency,
                        'tiers_count' => $program->tiers->count(),
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'programs' => $programs,
                'count' => count($programs),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
