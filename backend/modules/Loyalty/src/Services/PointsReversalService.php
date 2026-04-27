<?php

namespace Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PointsReversalService
{
    public function __construct(
        private TierUpgradeService $tierUpgrade
    ) {}

    /**
     * Reverse earned points (for refunds/voids)
     * Deducts points that were previously awarded
     */
    public function reverseEarn(
        int $customerId,
        string $referenceType,
        int $referenceId,
        string $reason,
        ?string $idempotencyKey = null
    ): array {
        return DB::transaction(function () use ($customerId, $referenceType, $referenceId, $reason, $idempotencyKey) {
            $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency check: prevent duplicate reversals
            if ($idempotencyKey) {
                $existing = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [
                        'success' => true,
                        'points_reversed' => abs($existing->points),
                        'new_balance' => $existing->balance_after,
                        'transaction' => $existing,
                        'idempotent' => true,
                    ];
                }
            }

            // Find the original earn transaction
            $originalTransaction = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                ->where('type', 'earn')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            if (!$originalTransaction) {
                Log::warning('No original earn transaction found for reversal', [
                    'customer_id' => $customerId,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
                
                return [
                    'success' => false,
                    'message' => 'No original transaction found to reverse',
                    'points_reversed' => 0,
                ];
            }

            $pointsToReverse = $originalTransaction->points;

            // Deduct points (cannot go below zero)
            $newBalance = max(0, $customerLoyalty->current_points - $pointsToReverse);
            $actualReversed = $customerLoyalty->current_points - $newBalance;

            $customerLoyalty->update([
                'current_points' => $newBalance,
                'points_earned' => max(0, $customerLoyalty->points_earned - $actualReversed),
            ]);

            // Create reversal transaction
            $transaction = LoyaltyTransaction::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'type' => 'reverse_earn',
                'points' => -$actualReversed,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType . '_reversal',
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'description' => $reason,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
            ]);

            // Check for tier downgrade
            $this->tierUpgrade->checkAndUpgradeTier($customerLoyalty->id);

            return [
                'success' => true,
                'points_reversed' => $actualReversed,
                'new_balance' => $newBalance,
                'transaction' => $transaction,
                'original_transaction' => $originalTransaction,
                'idempotent' => false,
            ];
        });
    }

    /**
     * Restore redeemed points (for cancelled redemptions/refunds)
     * Returns points that were previously redeemed
     */
    public function restoreRedeem(
        int $customerId,
        string $referenceType,
        int $referenceId,
        string $reason,
        ?string $idempotencyKey = null
    ): array {
        return DB::transaction(function () use ($customerId, $referenceType, $referenceId, $reason, $idempotencyKey) {
            $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency check: prevent duplicate restorations
            if ($idempotencyKey) {
                $existing = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [
                        'success' => true,
                        'points_restored' => $existing->points,
                        'new_balance' => $existing->balance_after,
                        'transaction' => $existing,
                        'idempotent' => true,
                    ];
                }
            }

            // Find the original redeem transaction
            $originalTransaction = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                ->where('type', 'redeem')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            if (!$originalTransaction) {
                Log::warning('No original redeem transaction found for restoration', [
                    'customer_id' => $customerId,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
                
                return [
                    'success' => false,
                    'message' => 'No original redemption found to restore',
                    'points_restored' => 0,
                ];
            }

            $pointsToRestore = abs($originalTransaction->points);

            // Restore points
            $newBalance = $customerLoyalty->current_points + $pointsToRestore;

            $customerLoyalty->update([
                'current_points' => $newBalance,
                'points_redeemed' => max(0, $customerLoyalty->points_redeemed - $pointsToRestore),
            ]);

            // Create restoration transaction
            $transaction = LoyaltyTransaction::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'type' => 'restore_redeem',
                'points' => $pointsToRestore,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType . '_restoration',
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'description' => $reason,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
            ]);

            // Check for tier upgrade
            $this->tierUpgrade->checkAndUpgradeTier($customerLoyalty->id);

            return [
                'success' => true,
                'points_restored' => $pointsToRestore,
                'new_balance' => $newBalance,
                'transaction' => $transaction,
                'original_transaction' => $originalTransaction,
                'idempotent' => false,
            ];
        });
    }

    /**
     * Reverse both earn and redeem for a complete order reversal
     */
    public function reverseOrder(
        int $customerId,
        string $referenceType,
        int $referenceId,
        string $reason
    ): array {
        $results = [
            'earn_reversal' => null,
            'redeem_restoration' => null,
        ];

        // Reverse earned points
        try {
            $results['earn_reversal'] = $this->reverseEarn(
                $customerId,
                $referenceType,
                $referenceId,
                $reason,
                "{$referenceType}_reverse_earn_{$referenceId}"
            );
        } catch (\Exception $e) {
            Log::error('Failed to reverse earned points', [
                'customer_id' => $customerId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'error' => $e->getMessage(),
            ]);
        }

        // Restore redeemed points
        try {
            $results['redeem_restoration'] = $this->restoreRedeem(
                $customerId,
                $referenceType,
                $referenceId,
                $reason,
                "{$referenceType}_restore_redeem_{$referenceId}"
            );
        } catch (\Exception $e) {
            Log::error('Failed to restore redeemed points', [
                'customer_id' => $customerId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * TASK POS-010: Proportional loyalty reversal for partial returns
     * 
     * Reverse loyalty points proportionally based on the return amount
     * relative to the original order total.
     */
    public function reverseProportional(
        int $customerId,
        string $referenceType,
        int $referenceId,
        float $returnAmount,
        float $originalAmount,
        string $reason,
        ?string $idempotencyKey = null
    ): array {
        return DB::transaction(function () use (
            $customerId,
            $referenceType,
            $referenceId,
            $returnAmount,
            $originalAmount,
            $reason,
            $idempotencyKey
        ) {
            $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency check
            if ($idempotencyKey) {
                $existing = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [
                        'success' => true,
                        'points_reversed' => abs($existing->points),
                        'new_balance' => $existing->balance_after,
                        'transaction' => $existing,
                        'idempotent' => true,
                    ];
                }
            }

            // Calculate proportional ratio
            $returnRatio = $originalAmount > 0 ? ($returnAmount / $originalAmount) : 0;

            // Find the original earn transaction
            $originalEarnTransaction = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                ->where('type', 'earn')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            // Find the original redeem transaction (if any)
            $originalRedeemTransaction = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                ->where('type', 'redeem')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            $results = [
                'earn_reversal' => null,
                'redeem_restoration' => null,
                'return_ratio' => $returnRatio,
            ];

            // Reverse proportional earned points
            if ($originalEarnTransaction) {
                $pointsToReverse = (int) round($originalEarnTransaction->points * $returnRatio);
                
                if ($pointsToReverse > 0) {
                    $newBalance = max(0, $customerLoyalty->current_points - $pointsToReverse);
                    $actualReversed = $customerLoyalty->current_points - $newBalance;

                    $customerLoyalty->update([
                        'current_points' => $newBalance,
                        'points_earned' => max(0, $customerLoyalty->points_earned - $actualReversed),
                    ]);

                    $transaction = LoyaltyTransaction::create([
                        'customer_loyalty_id' => $customerLoyalty->id,
                        'type' => 'reverse_earn',
                        'points' => -$actualReversed,
                        'balance_after' => $newBalance,
                        'reference_type' => $referenceType . '_partial_reversal',
                        'reference_id' => $referenceId,
                        'idempotency_key' => $idempotencyKey,
                        'description' => $reason . " (proportional: {$returnRatio})",
                        'created_by' => TenantUserHelper::resolveTenantUserId(),
                        'created_at' => now(),
                    ]);

                    $results['earn_reversal'] = [
                        'success' => true,
                        'points_reversed' => $actualReversed,
                        'new_balance' => $newBalance,
                        'transaction' => $transaction,
                    ];
                }
            }

            // Restore proportional redeemed points
            if ($originalRedeemTransaction) {
                $pointsToRestore = (int) round(abs($originalRedeemTransaction->points) * $returnRatio);
                
                if ($pointsToRestore > 0) {
                    $newBalance = $customerLoyalty->current_points + $pointsToRestore;

                    $customerLoyalty->update([
                        'current_points' => $newBalance,
                        'points_redeemed' => max(0, $customerLoyalty->points_redeemed - $pointsToRestore),
                    ]);

                    $transaction = LoyaltyTransaction::create([
                        'customer_loyalty_id' => $customerLoyalty->id,
                        'type' => 'restore_redeem',
                        'points' => $pointsToRestore,
                        'balance_after' => $newBalance,
                        'reference_type' => $referenceType . '_partial_restoration',
                        'reference_id' => $referenceId,
                        'idempotency_key' => $idempotencyKey ? $idempotencyKey . '_redeem' : null,
                        'description' => $reason . " (proportional: {$returnRatio})",
                        'created_by' => TenantUserHelper::resolveTenantUserId(),
                        'created_at' => now(),
                    ]);

                    $results['redeem_restoration'] = [
                        'success' => true,
                        'points_restored' => $pointsToRestore,
                        'new_balance' => $newBalance,
                        'transaction' => $transaction,
                    ];
                }
            }

            // Check for tier changes
            $this->tierUpgrade->checkAndUpgradeTier($customerLoyalty->id);

            return $results;
        });
    }
}
