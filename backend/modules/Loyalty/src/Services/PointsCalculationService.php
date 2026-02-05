<?php

namespace Modules\Loyalty\Services;

use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\CustomerLoyalty;

class PointsCalculationService
{
    /**
     * Calculate points earned from an amount
     */
    public function calculatePointsFromAmount(float $amount, ?int $programId = null): int
    {
        $program = $programId 
            ? LoyaltyProgram::findOrFail($programId)
            : LoyaltyProgram::where('is_active', true)->first();

        if (!$program) {
            return 0;
        }

        $basePoints = floor($amount * $program->points_per_currency);

        return (int) $basePoints;
    }

    /**
     * Calculate points with tier multiplier
     */
    public function calculatePointsWithMultiplier(float $amount, int $customerId, ?int $programId = null): int
    {
        $basePoints = $this->calculatePointsFromAmount($amount, $programId);

        $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)->first();

        if (!$customerLoyalty || !$customerLoyalty->tier) {
            return $basePoints;
        }

        $multiplier = $customerLoyalty->tier->earning_multiplier;
        $totalPoints = floor($basePoints * $multiplier);

        return (int) $totalPoints;
    }

    /**
     * Calculate discount amount from points
     */
    public function calculateDiscountFromPoints(int $points, ?int $programId = null): float
    {
        $program = $programId 
            ? LoyaltyProgram::findOrFail($programId)
            : LoyaltyProgram::where('is_active', true)->first();

        if (!$program) {
            return 0.0;
        }

        $discount = $points * $program->currency_per_point;

        return round($discount, 2);
    }

    /**
     * Calculate maximum redeemable points for an invoice
     */
    public function calculateMaxRedeemablePoints(float $invoiceAmount, int $availablePoints, ?int $programId = null): int
    {
        $program = $programId 
            ? LoyaltyProgram::findOrFail($programId)
            : LoyaltyProgram::where('is_active', true)->first();

        if (!$program) {
            return 0;
        }

        // Maximum discount allowed (percentage of invoice)
        $maxDiscountAmount = $invoiceAmount * ($program->max_redemption_percent / 100);

        // Convert max discount to points
        $maxPointsByDiscount = floor($maxDiscountAmount / $program->currency_per_point);

        // Return the minimum of available points and max allowed points
        return min($availablePoints, $maxPointsByDiscount, $availablePoints);
    }

    /**
     * Validate if points can be redeemed
     */
    public function canRedeemPoints(int $points, int $customerId, ?int $programId = null): array
    {
        $program = $programId 
            ? LoyaltyProgram::findOrFail($programId)
            : LoyaltyProgram::where('is_active', true)->first();

        if (!$program) {
            return ['can_redeem' => false, 'reason' => 'No active loyalty program'];
        }

        if ($points < $program->min_redemption_points) {
            return [
                'can_redeem' => false, 
                'reason' => "Minimum {$program->min_redemption_points} points required"
            ];
        }

        $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)->first();

        if (!$customerLoyalty) {
            return ['can_redeem' => false, 'reason' => 'Customer not enrolled in loyalty program'];
        }

        if ($customerLoyalty->current_points < $points) {
            return [
                'can_redeem' => false, 
                'reason' => 'Insufficient points balance'
            ];
        }

        return ['can_redeem' => true];
    }
}
