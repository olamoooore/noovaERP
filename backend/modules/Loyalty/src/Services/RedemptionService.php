<?php

namespace Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyRedemption;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class RedemptionService
{
    public function __construct(
        private PointsCalculationService $pointsCalculation,
        private TierUpgradeService $tierUpgrade,
        private \Modules\Inventory\Services\InventoryService $inventoryService,
    ) {}

    /**
     * Redeem points for discount with idempotency protection
     */
    public function redeemForDiscount(
        int $customerId,
        int $points,
        ?int $invoiceId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $idempotencyKey = null
    ): array {
        return DB::transaction(function () use ($customerId, $points, $invoiceId, $referenceType, $referenceId, $idempotencyKey) {
            $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency check: prevent duplicate redemptions for same reference
            if ($referenceType && $referenceId) {
                $existing = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                    ->where('type', 'redeem')
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->first();

                if ($existing) {
                    $existingRedemption = LoyaltyRedemption::where('loyalty_transaction_id', $existing->id)->first();

                    return [
                        'success' => true,
                        'points_redeemed' => abs($existing->points),
                        'discount_amount' => $existingRedemption?->discount_amount ?? 0,
                        'new_balance' => $existing->balance_after,
                        'transaction' => $existing,
                        'redemption' => $existingRedemption,
                        'idempotent' => true,
                    ];
                }
            }

            // Additional idempotency check using explicit key
            if ($idempotencyKey) {
                $existing = LoyaltyTransaction::where('customer_loyalty_id', $customerLoyalty->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    $existingRedemption = LoyaltyRedemption::where('loyalty_transaction_id', $existing->id)->first();

                    return [
                        'success' => true,
                        'points_redeemed' => abs($existing->points),
                        'discount_amount' => $existingRedemption?->discount_amount ?? 0,
                        'new_balance' => $existing->balance_after,
                        'transaction' => $existing,
                        'redemption' => $existingRedemption,
                        'idempotent' => true,
                    ];
                }
            }

            // Validate redemption
            $validation = $this->pointsCalculation->canRedeemPoints(
                $points,
                $customerId,
                $customerLoyalty->loyalty_program_id
            );

            if (! $validation['can_redeem']) {
                throw new \Exception($validation['reason']);
            }

            // Calculate discount amount
            $discountAmount = $this->pointsCalculation->calculateDiscountFromPoints(
                $points,
                $customerLoyalty->loyalty_program_id
            );

            // Deduct points
            $newBalance = $customerLoyalty->current_points - $points;
            $customerLoyalty->update([
                'current_points' => $newBalance,
                'points_redeemed' => $customerLoyalty->points_redeemed + $points,
            ]);

            // Determine reference type
            $finalRefType = $referenceType ?? ($invoiceId ? 'invoice' : 'manual');
            $finalRefId = $referenceId ?? $invoiceId;

            // Create transaction record with idempotency key
            $transaction = LoyaltyTransaction::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'type' => 'redeem',
                'points' => -$points,
                'balance_after' => $newBalance,
                'reference_type' => $finalRefType,
                'reference_id' => $finalRefId,
                'idempotency_key' => $idempotencyKey,
                'description' => "Redeemed {$points} points for discount",
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
            ]);

            // Create redemption record
            $redemption = LoyaltyRedemption::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'loyalty_reward_id' => null,
                'loyalty_transaction_id' => $transaction->id,
                'type' => 'discount',
                'points_used' => $points,
                'discount_amount' => $discountAmount,
                'invoice_id' => $invoiceId,
                'status' => 'completed',
                'redeemed_at' => now(),
            ]);

            // Check for tier downgrade (if applicable)
            $this->tierUpgrade->checkAndUpgradeTier($customerLoyalty->id);

            return [
                'success' => true,
                'points_redeemed' => $points,
                'discount_amount' => $discountAmount,
                'new_balance' => $newBalance,
                'transaction' => $transaction,
                'redemption' => $redemption,
                'idempotent' => false,
            ];
        });
    }

    /**
     * Redeem points for a reward
     */
    public function redeemForReward(
        int $customerId,
        int $rewardId
    ): array {
        return DB::transaction(function () use ($customerId, $rewardId) {
            $reward = LoyaltyReward::where('is_active', true)->findOrFail($rewardId);
            $isProductBacked = ! empty($reward->product_id);

            $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            $points = $reward->points_cost;

            // Validate redemption
            $validation = $this->pointsCalculation->canRedeemPoints(
                $points,
                $customerId,
                $customerLoyalty->loyalty_program_id
            );

            if (! $validation['can_redeem']) {
                throw new \Exception($validation['reason']);
            }

            // Check quota-only availability for non-product rewards.
            if (! $isProductBacked
                && $reward->reward_quota_quantity !== null
                && $reward->reward_quota_quantity <= 0
            ) {
                throw new \Exception('Reward is out of stock');
            }

            // Product-backed rewards require explicit warehouse routing.
            $warehouseId = null;
            if ($isProductBacked) {
                $warehouseId = (int) ($reward->warehouse_id ?? 0);
                if ($warehouseId <= 0) {
                    throw new \RuntimeException(
                        'Cannot redeem product-backed loyalty reward without explicit warehouse_id.'
                    );
                }
            }

            // Deduct points
            $newBalance = $customerLoyalty->current_points - $points;
            $customerLoyalty->update([
                'current_points' => $newBalance,
                'points_redeemed' => $customerLoyalty->points_redeemed + $points,
            ]);

            // Create transaction record
            $transaction = LoyaltyTransaction::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'type' => 'redeem',
                'points' => -$points,
                'balance_after' => $newBalance,
                'reference_type' => 'reward',
                'reference_id' => $reward->id,
                'description' => "Redeemed reward: {$reward->name}",
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
            ]);

            // Create redemption record
            $redemption = LoyaltyRedemption::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'loyalty_reward_id' => $reward->id,
                'loyalty_transaction_id' => $transaction->id,
                'type' => 'reward',
                'points_used' => $points,
                'discount_amount' => null,
                'invoice_id' => null,
                'status' => 'pending',
                'redeemed_at' => now(),
            ]);

            // Product-backed: stock authority is Inventory only.
            if ($isProductBacked) {
                $this->inventoryService->adjustStock(
                    productId: $reward->product_id,
                    warehouseId: $warehouseId,
                    quantity: 1,
                    type: 'sale',
                    reference: "Loyalty Reward Redemption #{$redemption->id}",
                    referenceType: 'LoyaltyRedemption',
                    referenceId: $redemption->id,
                    notes: "Loyalty reward redeemed: {$reward->name}"
                );
            }

            // Non-product rewards: reward_quota_quantity is quota.
            if (! $isProductBacked && $reward->reward_quota_quantity !== null) {
                $reward->decrement('reward_quota_quantity');
            }

            // Check for tier downgrade
            $this->tierUpgrade->checkAndUpgradeTier($customerLoyalty->id);

            return [
                'success' => true,
                'reward' => $reward,
                'points_redeemed' => $points,
                'new_balance' => $newBalance,
                'transaction' => $transaction,
                'redemption' => $redemption,
            ];
        });
    }

    /**
     * Cancel a redemption
     */
    public function cancelRedemption(int $redemptionId): array
    {
        return DB::transaction(function () use ($redemptionId) {
            $redemption = LoyaltyRedemption::with('customerLoyalty', 'reward')
                ->findOrFail($redemptionId);

            if ($redemption->status === 'cancelled') {
                throw new \Exception('Redemption already cancelled');
            }

            if ($redemption->status === 'completed' && $redemption->invoice_id) {
                throw new \Exception('Cannot cancel completed redemption with invoice');
            }

            // Refund points
            $customerLoyalty = $redemption->customerLoyalty;
            $newBalance = $customerLoyalty->current_points + $redemption->points_used;

            $customerLoyalty->update([
                'current_points' => $newBalance,
                'points_redeemed' => $customerLoyalty->points_redeemed - $redemption->points_used,
            ]);

            // Create refund transaction
            $transaction = LoyaltyTransaction::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'type' => 'adjust',
                'points' => $redemption->points_used,
                'balance_after' => $newBalance,
                'reference_type' => 'redemption_cancel',
                'reference_id' => $redemption->id,
                'description' => 'Refund from cancelled redemption',
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
            ]);

            // Update redemption status
            $redemption->update(['status' => 'cancelled']);

            // Restore quota/stock based on reward type.
            if ($redemption->reward) {
                $isProductBacked = ! empty($redemption->reward->product_id);

                if ($isProductBacked) {
                    $warehouseId = (int) ($redemption->reward->warehouse_id ?? 0);
                    if ($warehouseId <= 0) {
                        throw new \RuntimeException(
                            'Cannot cancel product-backed loyalty reward without explicit warehouse_id.'
                        );
                    }

                    $this->inventoryService->adjustStock(
                        productId: $redemption->reward->product_id,
                        warehouseId: $warehouseId,
                        quantity: 1,
                        type: 'in',
                        reference: "Loyalty Reward Cancellation #{$redemption->id}",
                        referenceType: 'LoyaltyRedemptionCancel',
                        referenceId: $redemption->id,
                        notes: "Loyalty reward redemption cancelled: {$redemption->reward->name}"
                    );
                }

                // Non-product rewards: restore quota counter.
                if (! $isProductBacked && $redemption->reward->reward_quota_quantity !== null) {
                    $redemption->reward->increment('reward_quota_quantity');
                }
            }

            // Check for tier upgrade
            $this->tierUpgrade->checkAndUpgradeTier($customerLoyalty->id);

            return [
                'success' => true,
                'points_refunded' => $redemption->points_used,
                'new_balance' => $newBalance,
                'transaction' => $transaction,
            ];
        });
    }
}
