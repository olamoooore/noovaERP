<?php

namespace Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyRedemption;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\Loyalty\Models\LoyaltyTransaction;

class LoyaltyDashboardController
{
    /**
     * Get loyalty dashboard statistics
     */
    public function index(): JsonResponse
    {
        // Total members
        $totalMembers = CustomerLoyalty::count();

        // Total points issued (lifetime points sum)
        $totalPointsIssued = CustomerLoyalty::sum('lifetime_points');

        // Total points redeemed
        $totalPointsRedeemed = CustomerLoyalty::sum('points_redeemed');

        // Total discount given
        $totalDiscountGiven = LoyaltyRedemption::where('status', 'completed')
            ->sum('discount_amount');

        // Active rewards
        $activeRewards = LoyaltyReward::where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->count();

        // Tier distribution
        $tierDistribution = CustomerLoyalty::select('loyalty_tier_id')
            ->with('tier:id,name')
            ->get()
            ->groupBy('tier.name')
            ->map(function ($group, $tierName) use ($totalMembers) {
                $count = $group->count();

                return [
                    'name' => $tierName,
                    'count' => $count,
                    'percentage' => $totalMembers > 0 ? round(($count / $totalMembers) * 100) : 0,
                ];
            })
            ->values();

        // Recent earnings (last 10)
        $recentEarnings = LoyaltyTransaction::where('type', 'earn')
            ->with('customerLoyalty.customer:id,name')
            ->latest('created_at')
            ->take(10)
            ->get()
            ->map(function ($transaction) {
                $date = $transaction->created_at;
                if (is_string($date)) {
                    $date = \Carbon\Carbon::parse($date);
                }

                return [
                    'id' => $transaction->id,
                    'customer_name' => $transaction->customerLoyalty->customer->name ?? 'Unknown',
                    'description' => $transaction->description,
                    'points' => $transaction->points,
                    'date' => $date->format('Y-m-d'),
                ];
            });

        // Recent redemptions (last 10)
        $recentRedemptions = LoyaltyRedemption::with([
            'customerLoyalty.customer:id,name',
            'reward:id,name',
        ])
            ->latest('redeemed_at')
            ->take(10)
            ->get()
            ->map(function ($redemption) {
                $type = $redemption->reward_id ? 'Reward: '.($redemption->reward->name ?? 'Unknown') : 'Discount';
                $date = $redemption->redeemed_at;
                if (is_string($date)) {
                    $date = \Carbon\Carbon::parse($date);
                }

                return [
                    'id' => $redemption->id,
                    'customer_name' => $redemption->customerLoyalty->customer->name ?? 'Unknown',
                    'type' => $type,
                    'points' => $redemption->points_used,
                    'date' => $date->format('Y-m-d'),
                ];
            });

        return response()->json([
            'total_members' => $totalMembers,
            'total_points_issued' => $totalPointsIssued,
            'total_points_redeemed' => $totalPointsRedeemed,
            'total_discount_given' => (float) $totalDiscountGiven,
            'active_rewards' => $activeRewards,
            'tier_distribution' => $tierDistribution,
            'recent_earnings' => $recentEarnings,
            'recent_redemptions' => $recentRedemptions,
        ]);
    }
}
