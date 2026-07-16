<?php

namespace Modules\EventManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Models\EligibilityDecision;
use Modules\EventManagement\Models\EligibilityDecisionAppeal;
use Modules\EventManagement\Models\LoyaltyRuleVersion;

class EligibilityAuditService
{
    /**
     * Log an eligibility decision with complete audit trail
     */
    public function logEligibilityDecision(
        int $customerId,
        int $eventId,
        string $decisionType,
        string $decisionResult,
        string $decisionReason,
        array $decisionData,
        ?string $ruleVersion = null
    ): EligibilityDecision {
        try {
            // Get current rule version if not provided
            if (! $ruleVersion) {
                $ruleVersion = $this->getCurrentRuleVersion($decisionType);
            }

            // Create eligibility decision record
            $decision = EligibilityDecision::create([
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'decision_type' => $decisionType,
                'decision_result' => $decisionResult,
                'decision_reason' => $decisionReason,
                'rule_version_applied' => $ruleVersion,
                'decision_data' => $decisionData,
                'decided_at' => Carbon::now(),
            ]);

            Log::info('Eligibility decision logged', [
                'decision_id' => $decision->id,
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'decision_type' => $decisionType,
                'decision_result' => $decisionResult,
                'rule_version' => $ruleVersion,
            ]);

            return $decision;

        } catch (\Exception $e) {
            Log::error('Failed to log eligibility decision', [
                'customer_id' => $customerId,
                'event_id' => $eventId,
                'decision_type' => $decisionType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Log tier access decision with detailed reasoning
     */
    public function logTierAccessDecision(
        int $customerId,
        int $eventId,
        string $tierLevel,
        bool $accessGranted,
        array $tierData,
        array $customerData
    ): EligibilityDecision {
        $decisionReason = $accessGranted
            ? "Tier access granted for level: {$tierLevel}"
            : 'Tier access denied - insufficient tier level or requirements not met';

        $decisionData = [
            'tier_level_required' => $tierLevel,
            'customer_tier_data' => $customerData,
            'tier_requirements' => $tierData,
            'access_timestamp' => Carbon::now()->toISOString(),
            'decision_factors' => $this->analyzeTierEligibility($customerData, $tierData),
        ];

        return $this->logEligibilityDecision(
            $customerId,
            $eventId,
            'tier_access',
            $accessGranted ? 'allowed' : 'blocked',
            $decisionReason,
            $decisionData
        );
    }

    /**
     * Log early access decision with timing and eligibility factors
     */
    public function logEarlyAccessDecision(
        int $customerId,
        int $eventId,
        bool $accessGranted,
        array $earlyAccessRules,
        array $customerProfile
    ): EligibilityDecision {
        $decisionReason = $accessGranted
            ? 'Early access granted based on eligibility criteria'
            : 'Early access denied - criteria not met or capacity exhausted';

        $decisionData = [
            'early_access_rules' => $earlyAccessRules,
            'customer_profile' => $customerProfile,
            'access_window' => [
                'start' => $earlyAccessRules['start_time'] ?? null,
                'end' => $earlyAccessRules['end_time'] ?? null,
            ],
            'eligibility_factors' => $this->analyzeEarlyAccessEligibility($customerProfile, $earlyAccessRules),
        ];

        return $this->logEligibilityDecision(
            $customerId,
            $eventId,
            'early_access',
            $accessGranted ? 'allowed' : 'blocked',
            $decisionReason,
            $decisionData
        );
    }

    /**
     * Log discount eligibility decision
     */
    public function logDiscountEligibilityDecision(
        int $customerId,
        int $eventId,
        string $discountType,
        bool $eligible,
        array $discountRules,
        array $customerHistory
    ): EligibilityDecision {
        $decisionReason = $eligible
            ? "Discount eligibility confirmed for: {$discountType}"
            : 'Discount eligibility denied - requirements not satisfied';

        $decisionData = [
            'discount_type' => $discountType,
            'discount_rules' => $discountRules,
            'customer_history' => $customerHistory,
            'eligibility_calculation' => $this->calculateDiscountEligibility($customerHistory, $discountRules),
        ];

        return $this->logEligibilityDecision(
            $customerId,
            $eventId,
            'discount_eligibility',
            $eligible ? 'allowed' : 'blocked',
            $decisionReason,
            $decisionData
        );
    }

    /**
     * Log points redemption decision
     */
    public function logPointsRedemptionDecision(
        int $customerId,
        int $eventId,
        int $pointsRequested,
        bool $redemptionAllowed,
        array $pointsBalance,
        array $redemptionRules
    ): EligibilityDecision {
        $decisionReason = $redemptionAllowed
            ? "Points redemption approved for {$pointsRequested} points"
            : 'Points redemption denied - insufficient balance or restrictions apply';

        $decisionData = [
            'points_requested' => $pointsRequested,
            'points_balance' => $pointsBalance,
            'redemption_rules' => $redemptionRules,
            'balance_verification' => $this->verifyPointsBalance($customerId, $pointsRequested),
        ];

        return $this->logEligibilityDecision(
            $customerId,
            $eventId,
            'points_redemption',
            $redemptionAllowed ? 'allowed' : 'blocked',
            $decisionReason,
            $decisionData
        );
    }

    /**
     * Create an appeal for an eligibility decision
     */
    public function createDecisionAppeal(
        int $originalDecisionId,
        int $customerId,
        string $appealType,
        string $appealReason,
        array $appealData = []
    ): EligibilityDecisionAppeal {
        return EligibilityDecisionAppeal::create([
            'original_decision_id' => $originalDecisionId,
            'customer_id' => $customerId,
            'appeal_type' => $appealType,
            'appeal_reason' => $appealReason,
            'appeal_status' => 'pending',
            'appeal_data' => $appealData,
            'appealed_at' => Carbon::now(),
        ]);
    }

    /**
     * Process an appeal review
     */
    public function reviewAppeal(
        int $appealId,
        int $reviewerStaffId,
        string $appealStatus,
        string $reviewNotes
    ): EligibilityDecisionAppeal {
        $appeal = EligibilityDecisionAppeal::findOrFail($appealId);

        $appeal->update([
            'appeal_status' => $appealStatus,
            'reviewed_by_staff_id' => $reviewerStaffId,
            'review_notes' => $reviewNotes,
            'reviewed_at' => Carbon::now(),
        ]);

        Log::info('Appeal reviewed', [
            'appeal_id' => $appealId,
            'reviewer_id' => $reviewerStaffId,
            'status' => $appealStatus,
        ]);

        return $appeal;
    }

    /**
     * Get eligibility decision history for a customer
     */
    public function getCustomerDecisionHistory(
        int $customerId,
        array $filters = []
    ): array {
        $query = EligibilityDecision::where('customer_id', $customerId);

        if (isset($filters['event_id'])) {
            $query->where('event_id', $filters['event_id']);
        }

        if (isset($filters['decision_type'])) {
            $query->where('decision_type', $filters['decision_type']);
        }

        if (isset($filters['decision_result'])) {
            $query->where('decision_result', $filters['decision_result']);
        }

        if (isset($filters['date_from'])) {
            $query->where('decided_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('decided_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('decided_at', 'desc')
            ->with(['event', 'customer'])
            ->get()
            ->toArray();
    }

    /**
     * Generate eligibility decision report
     */
    public function generateDecisionReport(array $filters = []): array
    {
        $query = EligibilityDecision::query();

        // Apply filters
        if (isset($filters['event_id'])) {
            $query->where('event_id', $filters['event_id']);
        }

        if (isset($filters['decision_type'])) {
            $query->where('decision_type', $filters['decision_type']);
        }

        if (isset($filters['date_from'])) {
            $query->where('decided_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('decided_at', '<=', $filters['date_to']);
        }

        // Get summary statistics
        $summary = $query->select([
            'decision_type',
            'decision_result',
            DB::raw('COUNT(*) as decision_count'),
            DB::raw('COUNT(DISTINCT customer_id) as unique_customers'),
        ])
            ->groupBy(['decision_type', 'decision_result'])
            ->get()
            ->toArray();

        // Get detailed decisions
        $decisions = $query->with(['event', 'customer'])
            ->orderBy('decided_at', 'desc')
            ->limit(1000)
            ->get()
            ->toArray();

        return [
            'summary' => $summary,
            'decisions' => $decisions,
            'total_decisions' => $query->count(),
            'report_generated_at' => Carbon::now()->toISOString(),
        ];
    }

    /**
     * Get current rule version for a decision type
     */
    private function getCurrentRuleVersion(string $decisionType): string
    {
        $ruleVersion = LoyaltyRuleVersion::where('rule_name', $decisionType)
            ->where('is_active', true)
            ->where('effective_from', '<=', Carbon::now())
            ->where(function ($query) {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', Carbon::now());
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        return $ruleVersion ? $ruleVersion->rule_version : 'default_v1.0';
    }

    /**
     * Analyze tier eligibility factors
     */
    private function analyzeTierEligibility(array $customerData, array $tierData): array
    {
        return [
            'tier_level_check' => [
                'required' => $tierData['level'] ?? 'unknown',
                'customer_level' => $customerData['tier_level'] ?? 'none',
                'meets_requirement' => ($customerData['tier_level'] ?? 0) >= ($tierData['level'] ?? 999),
            ],
            'spending_check' => [
                'required' => $tierData['min_spending'] ?? 0,
                'customer_spending' => $customerData['total_spending'] ?? 0,
                'meets_requirement' => ($customerData['total_spending'] ?? 0) >= ($tierData['min_spending'] ?? 0),
            ],
            'loyalty_points_check' => [
                'required' => $tierData['min_points'] ?? 0,
                'customer_points' => $customerData['loyalty_points'] ?? 0,
                'meets_requirement' => ($customerData['loyalty_points'] ?? 0) >= ($tierData['min_points'] ?? 0),
            ],
        ];
    }

    /**
     * Analyze early access eligibility factors
     */
    private function analyzeEarlyAccessEligibility(array $customerProfile, array $earlyAccessRules): array
    {
        return [
            'tier_requirement' => [
                'required_tier' => $earlyAccessRules['min_tier'] ?? null,
                'customer_tier' => $customerProfile['tier_level'] ?? null,
                'meets_requirement' => ($customerProfile['tier_level'] ?? 0) >= ($earlyAccessRules['min_tier'] ?? 999),
            ],
            'purchase_history' => [
                'required_purchases' => $earlyAccessRules['min_purchases'] ?? 0,
                'customer_purchases' => $customerProfile['purchase_count'] ?? 0,
                'meets_requirement' => ($customerProfile['purchase_count'] ?? 0) >= ($earlyAccessRules['min_purchases'] ?? 0),
            ],
            'timing_check' => [
                'access_window_open' => Carbon::now()->between(
                    Carbon::parse($earlyAccessRules['start_time'] ?? '1970-01-01'),
                    Carbon::parse($earlyAccessRules['end_time'] ?? '2099-12-31')
                ),
            ],
        ];
    }

    /**
     * Calculate discount eligibility
     */
    private function calculateDiscountEligibility(array $customerHistory, array $discountRules): array
    {
        return [
            'loyalty_discount' => [
                'eligible' => ($customerHistory['loyalty_points'] ?? 0) >= ($discountRules['min_points'] ?? 0),
                'discount_percentage' => $discountRules['loyalty_discount_percentage'] ?? 0,
            ],
            'volume_discount' => [
                'eligible' => ($customerHistory['total_purchases'] ?? 0) >= ($discountRules['min_purchases'] ?? 0),
                'discount_percentage' => $discountRules['volume_discount_percentage'] ?? 0,
            ],
            'tier_discount' => [
                'eligible' => ($customerHistory['tier_level'] ?? 0) >= ($discountRules['min_tier'] ?? 0),
                'discount_percentage' => $discountRules['tier_discount_percentage'] ?? 0,
            ],
        ];
    }

    /**
     * Verify points balance for redemption
     */
    private function verifyPointsBalance(int $customerId, int $pointsRequested): array
    {
        // This would integrate with LoyaltyLedgerService for actual balance verification
        return [
            'balance_sufficient' => true, // Placeholder - would be actual verification
            'available_points' => 1000, // Placeholder - would be actual balance
            'pending_points' => 0,
            'verification_timestamp' => Carbon::now()->toISOString(),
        ];
    }
}
