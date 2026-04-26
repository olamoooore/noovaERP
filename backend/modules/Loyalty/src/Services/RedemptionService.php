<?php

namespace Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Warehouse;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Loyalty\Models\LoyaltyRedemption;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class RedemptionService
{
    public function __construct(
        private PointsCalculationService $pointsCalculation,
        private TierUpgradeService $tierUpgrade,
        private \Modules\Inventory\Services\InventoryService $inventoryService,
    ) {}

    /**
     * Redeem points for discount
     */
    public function redeemForDiscount(
        int $customerId,
        int $points,
        ?int $invoiceId = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): array {
        return DB::transaction(function () use ($customerId, $points, $invoiceId, $referenceType, $referenceId) {
            $customerLoyalty = CustomerLoyalty::where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // Validate redemption
            $validation = $this->pointsCalculation->canRedeemPoints(
                $points,
                $customerId,
                $customerLoyalty->loyalty_program_id
            );

            if (!$validation['can_redeem']) {
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

            // Create transaction record
            $transaction = LoyaltyTransaction::create([
                'customer_loyalty_id' => $customerLoyalty->id,
                'type' => 'redeem',
                'points' => -$points,
                'balance_after' => $newBalance,
                'reference_type' => $finalRefType,
                'reference_id' => $finalRefId,
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

            if (!$validation['can_redeem']) {
                throw new \Exception($validation['reason']);
            }

            // Check stock
            if ($reward->stock_quantity !== null && $reward->stock_quantity <= 0) {
                throw new \Exception('Reward is out of stock');
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

            // Decrease stock if applicable (route through InventoryService if product_id exists)
            if ($reward->stock_quantity !== null) {
                if ($reward->product_id) {
                    // Route through canonical InventoryService for product-based rewards
                    $warehouseId = Warehouse::getDefault()?->id;
                    if ($warehouseId) {
                        $this->inventoryService->adjustStock(
                            productId: $reward->product_id,
                            warehouseId: $warehouseId,
                            quantity: 1,
                            type: 'out',
                            reference: "Loyalty Reward Redemption #{$redemption->id}",
                            referenceType: 'LoyaltyRedemption',
                            referenceId: $redemption->id,
                            notes: "Loyalty reward redeemed: {$reward->name}"
                        );
                    }
                }
                // Update reward stock_quantity for tracking (even if product-based)
                $reward->decrement('stock_quantity');
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
                'description' => "Refund from cancelled redemption",
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
            ]);

            // Update redemption status
            $redemption->update(['status' => 'cancelled']);

            // Restore stock if reward (route through InventoryService if product_id exists)
            if ($redemption->reward && $redemption->reward->stock_quantity !== null) {
                if ($redemption->reward->product_id) {
                    // Route through canonical InventoryService for product-based rewards
                    $warehouseId = Warehouse::getDefault()?->id;
                    if ($warehouseId) {
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
                }
                // Update reward stock_quantity for tracking (even if product-based)
                $redemption->reward->increment('stock_quantity');
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
