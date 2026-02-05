<?php

namespace Modules\Loyalty\Services;

use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyTier;

class TierUpgradeService
{
    /**
     * Check and upgrade customer tier if eligible
     */
    public function checkAndUpgradeTier(int $customerLoyaltyId): ?LoyaltyTier
    {
        $customerLoyalty = CustomerLoyalty::with('tier', 'program')->findOrFail($customerLoyaltyId);

        $currentPoints = $customerLoyalty->current_points;
        $currentTier = $customerLoyalty->tier;

        // Find the appropriate tier based on points
        $newTier = LoyaltyTier::where('loyalty_program_id', $customerLoyalty->loyalty_program_id)
            ->where('min_points', '<=', $currentPoints)
            ->where(function ($query) use ($currentPoints) {
                $query->whereNull('max_points')
                    ->orWhere('max_points', '>=', $currentPoints);
            })
            ->orderBy('min_points', 'desc')
            ->first();

        // If no tier change needed
        if (!$newTier || ($currentTier && $newTier->id === $currentTier->id)) {
            return null;
        }

        // Upgrade tier
        $customerLoyalty->update([
            'loyalty_tier_id' => $newTier->id,
            'tier_upgraded_at' => now(),
        ]);

        return $newTier;
    }

    /**
     * Get next tier information
     */
    public function getNextTierInfo(int $customerLoyaltyId): ?array
    {
        $customerLoyalty = CustomerLoyalty::with('tier')->findOrFail($customerLoyaltyId);

        if (!$customerLoyalty->tier) {
            return null;
        }

        $nextTier = LoyaltyTier::where('loyalty_program_id', $customerLoyalty->loyalty_program_id)
            ->where('min_points', '>', $customerLoyalty->tier->min_points)
            ->orderBy('min_points', 'asc')
            ->first();

        if (!$nextTier) {
            return null; // Already at highest tier
        }

        $pointsNeeded = $nextTier->min_points - $customerLoyalty->current_points;

        return [
            'next_tier' => $nextTier,
            'points_needed' => max(0, $pointsNeeded),
            'progress_percentage' => $this->calculateTierProgress($customerLoyalty),
        ];
    }

    /**
     * Calculate progress towards next tier
     */
    public function calculateTierProgress(CustomerLoyalty $customerLoyalty): float
    {
        if (!$customerLoyalty->tier) {
            return 0.0;
        }

        $currentTier = $customerLoyalty->tier;
        $currentPoints = $customerLoyalty->current_points;

        // Find next tier
        $nextTier = LoyaltyTier::where('loyalty_program_id', $customerLoyalty->loyalty_program_id)
            ->where('min_points', '>', $currentTier->min_points)
            ->orderBy('min_points', 'asc')
            ->first();

        if (!$nextTier) {
            return 100.0; // At highest tier
        }

        $tierMin = $currentTier->min_points;
        $tierMax = $nextTier->min_points;
        $range = $tierMax - $tierMin;

        if ($range <= 0) {
            return 100.0;
        }

        $progress = (($currentPoints - $tierMin) / $range) * 100;

        return round(min(100, max(0, $progress)), 2);
    }

    /**
     * Get all tiers for a program
     */
    public function getAllTiers(int $programId): \Illuminate\Database\Eloquent\Collection
    {
        return LoyaltyTier::where('loyalty_program_id', $programId)
            ->orderBy('sort_order')
            ->get();
    }
}
