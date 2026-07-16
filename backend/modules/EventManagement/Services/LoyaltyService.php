<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Models\CustomerTier;
use Modules\EventManagement\Models\LoyaltyPointTransaction;
use Modules\EventManagement\Models\TierBenefit;
use Modules\EventManagement\Models\TierBenefitUsage;

class LoyaltyService
{
    private FanTierService $fanTierService;

    public function __construct(FanTierService $fanTierService)
    {
        $this->fanTierService = $fanTierService;
    }

    /**
     * Award points to a customer for a specific action.
     */
    public function awardPoints(
        string $tenantId,
        int $customerId,
        int $basePoints,
        string $sourceType,
        ?int $sourceId = null,
        ?string $description = null
    ): array {
        return DB::transaction(function () use ($tenantId, $customerId, $basePoints, $sourceType, $sourceId, $description) {
            // Get customer tier for potential multipliers
            $customerTier = $this->fanTierService->getCustomerTier($tenantId, $customerId);

            // Calculate final points with multipliers
            $multiplier = $this->getPointsMultiplier($tenantId, $customerId, $sourceType);
            $finalPoints = (int) ($basePoints * $multiplier);

            // Ensure customer tier exists (create default if needed)
            if (! $customerTier) {
                $customerTier = $this->ensureCustomerTier($tenantId, $customerId);
            }

            // Add points to customer tier
            $customerTier->addPoints($finalPoints, $sourceType, $sourceId, $description);

            // Record multiplier usage if applicable
            if ($multiplier > 1.0) {
                $this->recordMultiplierUsage($tenantId, $customerId, $sourceType, $sourceId, $multiplier);
            }

            // Check for tier progression
            $this->fanTierService->processAutomaticTierProgression($tenantId, $customerId);

            return [
                'base_points' => $basePoints,
                'multiplier' => $multiplier,
                'final_points' => $finalPoints,
                'new_balance' => $customerTier->fresh()->current_points,
            ];
        });
    }

    /**
     * Redeem points for a customer.
     */
    public function redeemPoints(
        string $tenantId,
        int $customerId,
        int $points,
        string $sourceType,
        ?int $sourceId = null,
        ?string $description = null
    ): array {
        return DB::transaction(function () use ($tenantId, $customerId, $points, $sourceType, $sourceId, $description) {
            $customerTier = $this->fanTierService->getCustomerTier($tenantId, $customerId);

            if (! $customerTier) {
                return [
                    'success' => false,
                    'error' => 'Customer has no tier assigned',
                ];
            }

            if ($customerTier->current_points < $points) {
                return [
                    'success' => false,
                    'error' => 'Insufficient points balance',
                    'current_balance' => $customerTier->current_points,
                    'requested' => $points,
                ];
            }

            $success = $customerTier->redeemPoints($points, $sourceType, $sourceId, $description);

            return [
                'success' => $success,
                'points_redeemed' => $points,
                'new_balance' => $customerTier->fresh()->current_points,
            ];
        });
    }

    /**
     * Get customer's points balance and history.
     */
    public function getCustomerPointsInfo(string $tenantId, int $customerId): array
    {
        $customerTier = $this->fanTierService->getCustomerTier($tenantId, $customerId);

        if (! $customerTier) {
            return [
                'current_points' => 0,
                'lifetime_points' => 0,
                'tier' => null,
                'recent_transactions' => [],
                'expiring_soon' => [],
            ];
        }

        $recentTransactions = LoyaltyPointTransaction::forTenant($tenantId)
            ->forCustomer($customerId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $expiringSoon = LoyaltyPointTransaction::forTenant($tenantId)
            ->forCustomer($customerId)
            ->expiringSoon(30)
            ->orderBy('expires_at')
            ->get();

        return [
            'current_points' => $customerTier->current_points,
            'lifetime_points' => $customerTier->lifetime_points,
            'tier' => $customerTier->fanTier,
            'recent_transactions' => $recentTransactions,
            'expiring_soon' => $expiringSoon,
        ];
    }

    /**
     * Process expired points for all customers.
     */
    public function processExpiredPoints(string $tenantId): array
    {
        $expiredTransactions = LoyaltyPointTransaction::forTenant($tenantId)
            ->alreadyExpired()
            ->get();

        $processedCustomers = [];
        $totalPointsExpired = 0;

        foreach ($expiredTransactions->groupBy('customer_id') as $customerId => $transactions) {
            $customerPointsExpired = $transactions->sum('points_amount');

            if ($customerPointsExpired > 0) {
                $this->expireCustomerPoints($tenantId, $customerId, $customerPointsExpired, $transactions);
                $processedCustomers[] = $customerId;
                $totalPointsExpired += $customerPointsExpired;
            }
        }

        return [
            'customers_processed' => count($processedCustomers),
            'total_points_expired' => $totalPointsExpired,
            'processed_customer_ids' => $processedCustomers,
        ];
    }

    /**
     * Calculate discount amount based on points redemption.
     */
    public function calculatePointsDiscount(string $tenantId, int $customerId, int $pointsToRedeem, float $orderAmount): array
    {
        $customerTier = $this->fanTierService->getCustomerTier($tenantId, $customerId);

        if (! $customerTier || $customerTier->current_points < $pointsToRedeem) {
            return [
                'valid' => false,
                'error' => 'Insufficient points',
            ];
        }

        // Get points redemption rate (e.g., 100 points = $1)
        $redemptionRate = $this->getPointsRedemptionRate($tenantId);
        $discountAmount = $pointsToRedeem / $redemptionRate;

        // Cap discount at order amount
        $discountAmount = min($discountAmount, $orderAmount);

        return [
            'valid' => true,
            'points_to_redeem' => $pointsToRedeem,
            'discount_amount' => $discountAmount,
            'redemption_rate' => $redemptionRate,
            'remaining_balance' => $customerTier->current_points - $pointsToRedeem,
        ];
    }

    /**
     * Get points earning rules for different actions.
     */
    public function getPointsEarningRules(string $tenantId): array
    {
        // This would typically be configurable per tenant
        return [
            'booking_completed' => [
                'base_points' => 10,
                'per_dollar_spent' => 1,
            ],
            'event_attended' => [
                'base_points' => 50,
            ],
            'review_submitted' => [
                'base_points' => 25,
            ],
            'referral_successful' => [
                'base_points' => 100,
            ],
        ];
    }

    /**
     * Calculate points for a booking.
     */
    public function calculateBookingPoints(string $tenantId, float $bookingAmount): int
    {
        $rules = $this->getPointsEarningRules($tenantId);
        $bookingRule = $rules['booking_completed'];

        $basePoints = $bookingRule['base_points'];
        $amountPoints = (int) ($bookingAmount * $bookingRule['per_dollar_spent']);

        return $basePoints + $amountPoints;
    }

    /**
     * Get points multiplier for a customer and action type.
     */
    private function getPointsMultiplier(string $tenantId, int $customerId, string $sourceType): float
    {
        $benefits = $this->fanTierService->getCustomerBenefits($tenantId, $customerId);

        $multiplierBenefit = $benefits->first(function ($benefit) use ($sourceType) {
            return $benefit->benefit_type === TierBenefit::TYPE_POINTS_MULTIPLIER
                && $benefit->appliesTo($sourceType);
        });

        return $multiplierBenefit ? $multiplierBenefit->getPointsMultiplier() : 1.0;
    }

    /**
     * Record multiplier usage.
     */
    private function recordMultiplierUsage(string $tenantId, int $customerId, string $sourceType, ?int $sourceId, float $multiplier): void
    {
        $benefits = $this->fanTierService->getCustomerBenefits($tenantId, $customerId);

        $multiplierBenefit = $benefits->first(function ($benefit) use ($sourceType) {
            return $benefit->benefit_type === TierBenefit::TYPE_POINTS_MULTIPLIER
                && $benefit->appliesTo($sourceType);
        });

        if ($multiplierBenefit) {
            $multiplierBenefit->recordUsage(
                $customerId,
                TierBenefitUsage::USAGE_POINTS_MULTIPLIER,
                $sourceType,
                $sourceId,
                ['multiplier' => $multiplier]
            );
        }
    }

    /**
     * Ensure customer has a tier (create default if needed).
     */
    private function ensureCustomerTier(string $tenantId, int $customerId): CustomerTier
    {
        // Get the lowest tier (General tier)
        $defaultTier = FanTier::forTenant($tenantId)
            ->active()
            ->orderBy('priority_level')
            ->first();

        if (! $defaultTier) {
            throw new \Exception('No default tier available for tenant');
        }

        return $this->fanTierService->assignCustomerToTier($tenantId, $customerId, $defaultTier->id);
    }

    /**
     * Expire points for a specific customer.
     */
    private function expireCustomerPoints(string $tenantId, int $customerId, int $pointsToExpire, Collection $expiredTransactions): void
    {
        DB::transaction(function () use ($tenantId, $customerId, $pointsToExpire, $expiredTransactions) {
            $customerTier = $this->fanTierService->getCustomerTier($tenantId, $customerId);

            if ($customerTier) {
                // Deduct expired points from current balance
                $customerTier->decrement('current_points', $pointsToExpire);
            }

            // Create expiration transaction
            LoyaltyPointTransaction::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'transaction_type' => LoyaltyPointTransaction::TYPE_EXPIRED,
                'points_amount' => -$pointsToExpire,
                'source_type' => LoyaltyPointTransaction::SOURCE_EXPIRATION,
                'description' => "Points expired: {$expiredTransactions->count()} transactions",
                'metadata' => [
                    'expired_transaction_ids' => $expiredTransactions->pluck('id')->toArray(),
                ],
            ]);
        });
    }

    /**
     * Get points redemption rate (points per dollar).
     */
    private function getPointsRedemptionRate(string $tenantId): int
    {
        // This would typically be configurable per tenant
        return 100; // 100 points = $1
    }
}
