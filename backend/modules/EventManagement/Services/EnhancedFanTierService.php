<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * EnhancedFanTierService - Task 7.4 Integration
 * 
 * Enhanced fan tier service that integrates with EligibilityAuditService
 * to log all eligibility decisions and provide comprehensive audit trails.
 * 
 * Requirements: 23.1, 23.4, 23.5
 */
class EnhancedFanTierService
{
    protected EligibilityAuditService $eligibilityAuditService;
    protected LoyaltyLedgerService $loyaltyLedgerService;
    protected AuditService $auditService;

    public function __construct(
        EligibilityAuditService $eligibilityAuditService,
        LoyaltyLedgerService $loyaltyLedgerService,
        AuditService $auditService
    ) {
        $this->eligibilityAuditService = $eligibilityAuditService;
        $this->loyaltyLedgerService = $loyaltyLedgerService;
        $this->auditService = $auditService;
    }

    /**
     * Check tier access eligibility with comprehensive logging.
     * 
     * @param int $customerId Customer ID
     * @param int $eventId Event ID
     * @param array $context Request context
     * @return array Eligibility result
     * 
     * Requirements: 23.1, 23.5
     */
    public function checkTierAccess(int $customerId, int $eventId, array $context = []): array
    {
        try {
            // Get customer tier information
            $customerTier = $this->getCustomerTier($customerId);
            
            // Get event tier requirements
            $eventTierRequirements = $this->getEventTierRequirements($eventId);
            
            // Perform eligibility evaluation
            $evaluationResult = $this->evaluateTierEligibility($customerTier, $eventTierRequirements, $context);
            
            // Log the eligibility decision
            $decisionData = [
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'decision_type' => 'tier_access',
                'decision_result' => $evaluationResult['result'],
                'decision_reason' => $evaluationResult['reason'],
                'rule_name' => 'tier_access_rule',
                'input_data' => [
                    'customer_tier' => $customerTier,
                    'event_requirements' => $eventTierRequirements,
                    'context' => $context,
                ],
                'evaluation_log' => $evaluationResult['evaluation_steps'],
                'confidence_score' => $evaluationResult['confidence_score'],
                'context' => $context['source'] ?? 'web',
                'session_id' => $context['session_id'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'decided_by_system' => 'fan_tier_service',
            ];
            
            $auditResult = $this->eligibilityAuditService->logEligibilityDecision($decisionData);
            
            return [
                'success' => true,
                'eligible' => $evaluationResult['result'] === 'allowed',
                'decision_result' => $evaluationResult['result'],
                'reason' => $evaluationResult['reason'],
                'customer_tier' => $customerTier,
                'required_tier' => $eventTierRequirements['minimum_tier'] ?? null,
                'decision_id' => $auditResult['decision_id'] ?? null,
                'confidence_score' => $evaluationResult['confidence_score'],
                'additional_benefits' => $evaluationResult['additional_benefits'] ?? [],
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to check tier access', [
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check early access eligibility with logging.
     * 
     * @param int $customerId Customer ID
     * @param int $eventId Event ID
     * @param array $context Request context
     * @return array Eligibility result
     * 
     * Requirements: 23.1, 23.5
     */
    public function checkEarlyAccess(int $customerId, int $eventId, array $context = []): array
    {
        try {
            // Get customer tier and loyalty status
            $customerTier = $this->getCustomerTier($customerId);
            $loyaltyStatus = $this->getCustomerLoyaltyStatus($customerId);
            
            // Get event early access requirements
            $earlyAccessRequirements = $this->getEventEarlyAccessRequirements($eventId);
            
            // Perform eligibility evaluation
            $evaluationResult = $this->evaluateEarlyAccessEligibility(
                $customerTier, 
                $loyaltyStatus, 
                $earlyAccessRequirements, 
                $context
            );
            
            // Log the eligibility decision
            $decisionData = [
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'decision_type' => 'early_access',
                'decision_result' => $evaluationResult['result'],
                'decision_reason' => $evaluationResult['reason'],
                'rule_name' => 'early_access_rule',
                'input_data' => [
                    'customer_tier' => $customerTier,
                    'loyalty_status' => $loyaltyStatus,
                    'early_access_requirements' => $earlyAccessRequirements,
                    'context' => $context,
                ],
                'evaluation_log' => $evaluationResult['evaluation_steps'],
                'confidence_score' => $evaluationResult['confidence_score'],
                'context' => $context['source'] ?? 'web',
                'session_id' => $context['session_id'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'decided_by_system' => 'fan_tier_service',
            ];
            
            $auditResult = $this->eligibilityAuditService->logEligibilityDecision($decisionData);
            
            return [
                'success' => true,
                'eligible' => $evaluationResult['result'] === 'allowed',
                'decision_result' => $evaluationResult['result'],
                'reason' => $evaluationResult['reason'],
                'early_access_window' => $evaluationResult['early_access_window'] ?? null,
                'decision_id' => $auditResult['decision_id'] ?? null,
                'confidence_score' => $evaluationResult['confidence_score'],
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to check early access', [
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check discount eligibility with logging.
     * 
     * @param int $customerId Customer ID
     * @param int $eventId Event ID
     * @param array $context Request context
     * @return array Eligibility result
     * 
     * Requirements: 23.1, 23.5
     */
    public function checkDiscountEligibility(int $customerId, int $eventId, array $context = []): array
    {
        try {
            // Get customer information
            $customerTier = $this->getCustomerTier($customerId);
            $loyaltyStatus = $this->getCustomerLoyaltyStatus($customerId);
            $purchaseHistory = $this->getCustomerPurchaseHistory($customerId);
            
            // Get available discounts for event
            $availableDiscounts = $this->getEventDiscounts($eventId);
            
            // Evaluate discount eligibility
            $evaluationResult = $this->evaluateDiscountEligibility(
                $customerTier,
                $loyaltyStatus,
                $purchaseHistory,
                $availableDiscounts,
                $context
            );
            
            // Log the eligibility decision
            $decisionData = [
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'decision_type' => 'discount_eligibility',
                'decision_result' => $evaluationResult['result'],
                'decision_reason' => $evaluationResult['reason'],
                'rule_name' => 'discount_eligibility_rule',
                'input_data' => [
                    'customer_tier' => $customerTier,
                    'loyalty_status' => $loyaltyStatus,
                    'purchase_history' => $purchaseHistory,
                    'available_discounts' => $availableDiscounts,
                    'context' => $context,
                ],
                'evaluation_log' => $evaluationResult['evaluation_steps'],
                'confidence_score' => $evaluationResult['confidence_score'],
                'context' => $context['source'] ?? 'web',
                'session_id' => $context['session_id'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'decided_by_system' => 'fan_tier_service',
            ];
            
            $auditResult = $this->eligibilityAuditService->logEligibilityDecision($decisionData);
            
            return [
                'success' => true,
                'eligible' => $evaluationResult['result'] === 'allowed',
                'decision_result' => $evaluationResult['result'],
                'reason' => $evaluationResult['reason'],
                'eligible_discounts' => $evaluationResult['eligible_discounts'] ?? [],
                'maximum_discount' => $evaluationResult['maximum_discount'] ?? 0,
                'decision_id' => $auditResult['decision_id'] ?? null,
                'confidence_score' => $evaluationResult['confidence_score'],
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to check discount eligibility', [
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process points earning with ledger integration.
     * 
     * @param int $customerId Customer ID
     * @param int $bookingId Booking ID
     * @param array $earningData Earning data
     * @return array Earning result
     * 
     * Requirements: 23.4
     */
    public function processPointsEarning(int $customerId, int $bookingId, array $earningData): array
    {
        try {
            // Calculate points to earn based on tier and purchase
            $pointsCalculation = $this->calculatePointsEarning($customerId, $earningData);
            
            // Process through loyalty ledger
            $earningContext = [
                'reference_type' => 'booking',
                'reference_id' => $bookingId,
                'reason' => $pointsCalculation['reason'],
                'metadata' => [
                    'tier_multiplier' => $pointsCalculation['tier_multiplier'],
                    'base_points' => $pointsCalculation['base_points'],
                    'bonus_points' => $pointsCalculation['bonus_points'],
                    'purchase_amount' => $earningData['purchase_amount'] ?? 0,
                ],
                'idempotency_key' => "booking_points_{$bookingId}",
            ];
            
            $ledgerResult = $this->loyaltyLedgerService->earnPoints(
                $customerId,
                $pointsCalculation['total_points'],
                $earningContext
            );
            
            if ($ledgerResult['success']) {
                // Update customer tier if necessary
                $this->updateCustomerTierIfNeeded($customerId, $ledgerResult['points_balance']);
                
                // Log the points earning decision
                $decisionData = [
                    'customer_id' => $customerId,
                    'booking_id' => $bookingId,
                    'decision_type' => 'points_redemption', // Using existing enum value
                    'decision_result' => 'allowed',
                    'decision_reason' => "Points earned: {$pointsCalculation['total_points']}",
                    'rule_name' => 'points_earning_rule',
                    'input_data' => $earningData,
                    'evaluation_log' => $pointsCalculation['calculation_steps'],
                    'confidence_score' => 1.0,
                    'decided_by_system' => 'fan_tier_service',
                ];
                
                $this->eligibilityAuditService->logEligibilityDecision($decisionData);
            }
            
            return [
                'success' => $ledgerResult['success'],
                'points_earned' => $pointsCalculation['total_points'],
                'new_balance' => $ledgerResult['points_balance'] ?? null,
                'tier_multiplier' => $pointsCalculation['tier_multiplier'],
                'transaction_id' => $ledgerResult['transaction_id'] ?? null,
                'error' => $ledgerResult['error'] ?? null,
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to process points earning', [
                'customer_id' => $customerId,
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process points redemption with ledger integration.
     * 
     * @param int $customerId Customer ID
     * @param int $points Points to redeem
     * @param array $redemptionData Redemption data
     * @return array Redemption result
     * 
     * Requirements: 23.4
     */
    public function processPointsRedemption(int $customerId, int $points, array $redemptionData): array
    {
        try {
            // Check redemption eligibility
            $eligibilityCheck = $this->checkPointsRedemptionEligibility($customerId, $points, $redemptionData);
            
            if (!$eligibilityCheck['eligible']) {
                return [
                    'success' => false,
                    'error' => $eligibilityCheck['reason'],
                    'decision_id' => $eligibilityCheck['decision_id'] ?? null,
                ];
            }
            
            // Process through loyalty ledger
            $redemptionContext = [
                'reference_type' => $redemptionData['reference_type'] ?? 'manual',
                'reference_id' => $redemptionData['reference_id'] ?? null,
                'reason' => $redemptionData['reason'] ?? 'Points redeemed',
                'metadata' => $redemptionData['metadata'] ?? [],
                'idempotency_key' => $redemptionData['idempotency_key'] ?? null,
            ];
            
            $ledgerResult = $this->loyaltyLedgerService->redeemPoints($customerId, $points, $redemptionContext);
            
            return [
                'success' => $ledgerResult['success'],
                'points_redeemed' => $points,
                'new_balance' => $ledgerResult['points_balance'] ?? null,
                'transaction_id' => $ledgerResult['transaction_id'] ?? null,
                'error' => $ledgerResult['error'] ?? null,
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to process points redemption', [
                'customer_id' => $customerId,
                'points' => $points,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Private helper methods

    /**
     * Get customer tier information.
     */
    private function getCustomerTier(int $customerId): array
    {
        $tier = DB::table('customer_tiers')
            ->where('customer_id', $customerId)
            ->first();
        
        return [
            'tier_name' => $tier->tier_name ?? 'bronze',
            'tier_level' => $tier->tier_level ?? 1,
            'points_balance' => $tier->points_balance ?? 0,
            'tier_benefits' => json_decode($tier->tier_benefits ?? '[]', true),
            'tier_since' => $tier->tier_since ?? null,
        ];
    }

    /**
     * Get customer loyalty status.
     */
    private function getCustomerLoyaltyStatus(int $customerId): array
    {
        $currentBalance = $this->loyaltyLedgerService->getCurrentBalance($customerId);
        $availableBalance = $this->loyaltyLedgerService->getAvailableBalance($customerId);
        
        return [
            'current_balance' => $currentBalance,
            'available_balance' => $availableBalance,
            'lifetime_earned' => $this->getLifetimePointsEarned($customerId),
            'lifetime_redeemed' => $this->getLifetimePointsRedeemed($customerId),
        ];
    }

    /**
     * Get customer purchase history.
     */
    private function getCustomerPurchaseHistory(int $customerId): array
    {
        $history = DB::table('bookings')
            ->where('customer_id', $customerId)
            ->where('status', 'confirmed')
            ->selectRaw('
                COUNT(*) as total_bookings,
                SUM(total_amount) as total_spent,
                MAX(created_at) as last_purchase_date,
                AVG(total_amount) as average_order_value
            ')
            ->first();
        
        return [
            'total_bookings' => $history->total_bookings ?? 0,
            'total_spent' => $history->total_spent ?? 0,
            'last_purchase_date' => $history->last_purchase_date,
            'average_order_value' => $history->average_order_value ?? 0,
        ];
    }

    /**
     * Get event tier requirements.
     */
    private function getEventTierRequirements(int $eventId): array
    {
        $event = DB::table('events')
            ->leftJoin('event_tier_requirements as etr', 'events.id', '=', 'etr.event_id')
            ->where('events.id', $eventId)
            ->select([
                'events.name',
                'events.tier_restricted',
                'etr.minimum_tier_level',
                'etr.tier_benefits',
                'etr.tier_restrictions',
            ])
            ->first();
        
        return [
            'tier_restricted' => $event->tier_restricted ?? false,
            'minimum_tier' => $event->minimum_tier_level ?? 1,
            'tier_benefits' => json_decode($event->tier_benefits ?? '[]', true),
            'tier_restrictions' => json_decode($event->tier_restrictions ?? '[]', true),
        ];
    }

    /**
     * Get event early access requirements.
     */
    private function getEventEarlyAccessRequirements(int $eventId): array
    {
        $requirements = DB::table('event_early_access')
            ->where('event_id', $eventId)
            ->where('is_active', true)
            ->first();
        
        return [
            'enabled' => $requirements ? true : false,
            'minimum_tier_level' => $requirements->minimum_tier_level ?? 3,
            'early_access_hours' => $requirements->early_access_hours ?? 24,
            'early_access_start' => $requirements->early_access_start ?? null,
        ];
    }

    /**
     * Get event discounts.
     */
    private function getEventDiscounts(int $eventId): array
    {
        $discounts = DB::table('event_discounts')
            ->where('event_id', $eventId)
            ->where('is_active', true)
            ->where('valid_from', '<=', Carbon::now())
            ->where('valid_until', '>=', Carbon::now())
            ->get();
        
        return $discounts->map(function ($discount) {
            return [
                'discount_id' => $discount->id,
                'discount_type' => $discount->discount_type,
                'discount_value' => $discount->discount_value,
                'minimum_tier_level' => $discount->minimum_tier_level,
                'minimum_purchase_amount' => $discount->minimum_purchase_amount,
                'usage_limit' => $discount->usage_limit,
                'conditions' => json_decode($discount->conditions ?? '[]', true),
            ];
        })->toArray();
    }

    /**
     * Evaluate tier eligibility.
     */
    private function evaluateTierEligibility(array $customerTier, array $eventRequirements, array $context): array
    {
        $evaluationSteps = [];
        $confidence = 1.0;
        
        // Check if event is tier restricted
        if (!$eventRequirements['tier_restricted']) {
            $evaluationSteps[] = 'Event is not tier restricted - access allowed';
            return [
                'result' => 'allowed',
                'reason' => 'Event is open to all tiers',
                'evaluation_steps' => $evaluationSteps,
                'confidence_score' => $confidence,
            ];
        }
        
        // Check tier level
        $evaluationSteps[] = "Customer tier level: {$customerTier['tier_level']}";
        $evaluationSteps[] = "Required tier level: {$eventRequirements['minimum_tier']}";
        
        if ($customerTier['tier_level'] >= $eventRequirements['minimum_tier']) {
            $evaluationSteps[] = 'Tier level requirement met - access allowed';
            return [
                'result' => 'allowed',
                'reason' => "Customer tier '{$customerTier['tier_name']}' meets minimum requirement",
                'evaluation_steps' => $evaluationSteps,
                'confidence_score' => $confidence,
                'additional_benefits' => $eventRequirements['tier_benefits'],
            ];
        } else {
            $evaluationSteps[] = 'Tier level requirement not met - access blocked';
            return [
                'result' => 'blocked',
                'reason' => "Customer tier '{$customerTier['tier_name']}' does not meet minimum requirement",
                'evaluation_steps' => $evaluationSteps,
                'confidence_score' => $confidence,
            ];
        }
    }

    /**
     * Evaluate early access eligibility.
     */
    private function evaluateEarlyAccessEligibility(array $customerTier, array $loyaltyStatus, array $requirements, array $context): array
    {
        $evaluationSteps = [];
        $confidence = 1.0;
        
        if (!$requirements['enabled']) {
            $evaluationSteps[] = 'Early access not enabled for this event';
            return [
                'result' => 'blocked',
                'reason' => 'Early access not available for this event',
                'evaluation_steps' => $evaluationSteps,
                'confidence_score' => $confidence,
            ];
        }
        
        $evaluationSteps[] = "Customer tier level: {$customerTier['tier_level']}";
        $evaluationSteps[] = "Required tier level for early access: {$requirements['minimum_tier_level']}";
        
        if ($customerTier['tier_level'] >= $requirements['minimum_tier_level']) {
            $evaluationSteps[] = 'Early access tier requirement met - access allowed';
            return [
                'result' => 'allowed',
                'reason' => "Customer tier qualifies for {$requirements['early_access_hours']} hour early access",
                'evaluation_steps' => $evaluationSteps,
                'confidence_score' => $confidence,
                'early_access_window' => $requirements['early_access_hours'],
            ];
        } else {
            $evaluationSteps[] = 'Early access tier requirement not met - access blocked';
            return [
                'result' => 'blocked',
                'reason' => 'Customer tier does not qualify for early access',
                'evaluation_steps' => $evaluationSteps,
                'confidence_score' => $confidence,
            ];
        }
    }

    /**
     * Evaluate discount eligibility.
     */
    private function evaluateDiscountEligibility(array $customerTier, array $loyaltyStatus, array $purchaseHistory, array $availableDiscounts, array $context): array
    {
        $evaluationSteps = [];
        $eligibleDiscounts = [];
        $maxDiscount = 0;
        
        foreach ($availableDiscounts as $discount) {
            $evaluationSteps[] = "Evaluating discount: {$discount['discount_type']}";
            
            // Check tier requirement
            if ($customerTier['tier_level'] >= $discount['minimum_tier_level']) {
                $evaluationSteps[] = "Tier requirement met for {$discount['discount_type']}";
                $eligibleDiscounts[] = $discount;
                $maxDiscount = max($maxDiscount, $discount['discount_value']);
            } else {
                $evaluationSteps[] = "Tier requirement not met for {$discount['discount_type']}";
            }
        }
        
        if (empty($eligibleDiscounts)) {
            return [
                'result' => 'blocked',
                'reason' => 'No discounts available for customer tier',
                'evaluation_steps' => $evaluationSteps,
                'confidence_score' => 1.0,
            ];
        }
        
        return [
            'result' => 'allowed',
            'reason' => count($eligibleDiscounts) . ' discount(s) available',
            'evaluation_steps' => $evaluationSteps,
            'confidence_score' => 1.0,
            'eligible_discounts' => $eligibleDiscounts,
            'maximum_discount' => $maxDiscount,
        ];
    }

    /**
     * Calculate points earning.
     */
    private function calculatePointsEarning(int $customerId, array $earningData): array
    {
        $customerTier = $this->getCustomerTier($customerId);
        $basePoints = $earningData['base_points'] ?? 0;
        $tierMultiplier = $this->getTierMultiplier($customerTier['tier_level']);
        $bonusPoints = $earningData['bonus_points'] ?? 0;
        
        $totalPoints = ($basePoints * $tierMultiplier) + $bonusPoints;
        
        return [
            'base_points' => $basePoints,
            'tier_multiplier' => $tierMultiplier,
            'bonus_points' => $bonusPoints,
            'total_points' => $totalPoints,
            'reason' => "Points earned: {$basePoints} base × {$tierMultiplier} tier multiplier + {$bonusPoints} bonus",
            'calculation_steps' => [
                "Base points: {$basePoints}",
                "Tier multiplier ({$customerTier['tier_name']}): {$tierMultiplier}",
                "Bonus points: {$bonusPoints}",
                "Total: {$totalPoints}",
            ],
        ];
    }

    /**
     * Check points redemption eligibility.
     */
    private function checkPointsRedemptionEligibility(int $customerId, int $points, array $redemptionData): array
    {
        $availableBalance = $this->loyaltyLedgerService->getAvailableBalance($customerId);
        
        $decisionData = [
            'customer_id' => $customerId,
            'decision_type' => 'points_redemption',
            'decision_result' => $availableBalance >= $points ? 'allowed' : 'blocked',
            'decision_reason' => $availableBalance >= $points 
                ? "Sufficient balance for {$points} points redemption"
                : "Insufficient balance: {$availableBalance} available, {$points} requested",
            'rule_name' => 'points_redemption_rule',
            'input_data' => [
                'requested_points' => $points,
                'available_balance' => $availableBalance,
                'redemption_data' => $redemptionData,
            ],
            'evaluation_log' => [
                "Requested points: {$points}",
                "Available balance: {$availableBalance}",
                "Sufficient balance: " . ($availableBalance >= $points ? 'Yes' : 'No'),
            ],
            'confidence_score' => 1.0,
            'decided_by_system' => 'fan_tier_service',
        ];
        
        $auditResult = $this->eligibilityAuditService->logEligibilityDecision($decisionData);
        
        return [
            'eligible' => $availableBalance >= $points,
            'reason' => $decisionData['decision_reason'],
            'available_balance' => $availableBalance,
            'decision_id' => $auditResult['decision_id'] ?? null,
        ];
    }

    /**
     * Get tier multiplier.
     */
    private function getTierMultiplier(int $tierLevel): float
    {
        $multipliers = [
            1 => 1.0,  // Bronze
            2 => 1.25, // Silver
            3 => 1.5,  // Gold
            4 => 2.0,  // Platinum
            5 => 2.5,  // Diamond
        ];
        
        return $multipliers[$tierLevel] ?? 1.0;
    }

    /**
     * Get lifetime points earned.
     */
    private function getLifetimePointsEarned(int $customerId): int
    {
        return DB::table('loyalty_ledger')
            ->where('customer_id', $customerId)
            ->where('transaction_type', 'earn')
            ->sum('points_delta');
    }

    /**
     * Get lifetime points redeemed.
     */
    private function getLifetimePointsRedeemed(int $customerId): int
    {
        return abs(DB::table('loyalty_ledger')
            ->where('customer_id', $customerId)
            ->where('transaction_type', 'redeem')
            ->sum('points_delta'));
    }

    /**
     * Update customer tier if needed.
     */
    private function updateCustomerTierIfNeeded(int $customerId, int $newBalance): void
    {
        // This would implement tier upgrade logic based on points balance
        // For now, just log the potential upgrade
        Log::info('Checking tier upgrade eligibility', [
            'customer_id' => $customerId,
            'new_balance' => $newBalance,
        ]);
    }
}