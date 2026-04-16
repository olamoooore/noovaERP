<?php

namespace Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PointsEarningService
{
    public function __construct(
        private PointsCalculationService $pointsCalculation,
        private TierUpgradeService $tierUpgrade
    ) {}

    /**
     * Award points to a customer
     */
    public function awardPoints(
        int $customerId,
        float $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null
    ): array {
        return DB::transaction(function () use ($customerId, $amount, $referenceType, $referenceId, $description) {
            // Get or create customer loyalty account
            $customerLoyalty = $this->getOrCreateCustomerLoyalty($customerId);

            // Calculate points with tier multiplier
            $points = $this->pointsCalculation->calculatePointsWithMultiplier(
                $amount,
                $customerId,
                $customerLoyalty->loyalty_program_id
            );

            if ($points <= 0) {
                return [
                    'success' => false,
                    'message' => 'No points earned',
                    'points_earned' => 0,
                ];
            }

            // Calculate expiry date
            $program = LoyaltyProgram::findOrFail($customerLoyalty->loyalty_program_id);
            $expiresAt = now()->addMonths($program->points_expiry_months);

            // Update customer loyalty balance
            $newBalance = $customerLoyalty->current_points + $points;
            $customerLoyalty->update([
                'current_points' => $newBalance,
                'lifetime_points' => $customerLoyalty->lifetime_points + $points,
                'points_earned' => $customerLoyalty->points_earned + $points,
            ]);

            // Create transaction record
            $transaction = LoyaltyTransaction::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType ?? 'manual',
                'reference_id' => $referenceId,
                'description' => $description ?? "Earned {$points} points from purchase",
                'expires_at' => $expiresAt,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
            ]);

            // Check for tier upgrade
            $upgradedTier = $this->tierUpgrade->checkAndUpgradeTier($customerLoyalty->id);

            return [
                'success' => true,
                'points_earned' => $points,
                'new_balance' => $newBalance,
                'tier_upgraded' => $upgradedTier !== null,
                'new_tier' => $upgradedTier,
                'transaction' => $transaction,
                'expires_at' => $expiresAt,
            ];
        });
    }

    /**
     * Get or create customer loyalty account
     */
    private function getOrCreateCustomerLoyalty(int $customerId): CustomerLoyalty
    {
        $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)->first();

        if ($customerLoyalty) {
            return $customerLoyalty;
        }

        // Get active program
        $program = LoyaltyProgram::where('is_active', true)->firstOrFail();

        // Get starting tier (Bronze - lowest tier)
        $startingTier = $program->tiers()
            ->orderBy('sort_order', 'asc')
            ->first();

        // Create new loyalty account
        return CustomerLoyalty::create([
            'customer_id' => $customerId,
            'loyalty_program_id' => $program->id,
            'loyalty_tier_id' => $startingTier?->id,
            'current_points' => 0,
            'lifetime_points' => 0,
            'points_earned' => 0,
            'points_redeemed' => 0,
            'points_expired' => 0,
            'joined_at' => now(),
        ]);
    }

    /**
     * Manually adjust points (admin only)
     */
    public function adjustPoints(
        int $customerId,
        int $points,
        string $reason
    ): array {
        return DB::transaction(function () use ($customerId, $points, $reason) {
            $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            $newBalance = $customerLoyalty->current_points + $points;

            if ($newBalance < 0) {
                throw new \Exception('Adjustment would result in negative balance');
            }

            $customerLoyalty->update([
                'current_points' => $newBalance,
            ]);

            // Update lifetime/earned/redeemed based on adjustment type
            if ($points > 0) {
                $customerLoyalty->increment('lifetime_points', $points);
                $customerLoyalty->increment('points_earned', $points);
            } else {
                $customerLoyalty->increment('points_redeemed', abs($points));
            }

            // Create transaction record
            $transaction = LoyaltyTransaction::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'type' => 'adjust',
                'points' => $points,
                'balance_after' => $newBalance,
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $reason,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
            ]);

            // Check for tier change
            $this->tierUpgrade->checkAndUpgradeTier($customerLoyalty->id);

            return [
                'success' => true,
                'points_adjusted' => $points,
                'new_balance' => $newBalance,
                'transaction' => $transaction,
            ];
        });
    }
}
