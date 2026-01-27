<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Services\FraudTimelineService;
use Modules\EventManagement\Services\RiskScoringService;
use Modules\EventManagement\Services\ProgressiveFrictionService;

/**
 * FraudAnalyticsController
 * 
 * Provides fraud analytics dashboard interface for operations teams
 * with comprehensive fraud event timeline, pattern recognition,
 * and dispute resolution workflow integration.
 * 
 * Requirements: 24.3, 24.5
 */
class FraudAnalyticsController extends Controller
{
    private FraudTimelineService $fraudTimelineService;
    private RiskScoringService $riskScoringService;
    private ProgressiveFrictionService $progressiveFrictionService;
    
    public function __construct(
        FraudTimelineService $fraudTimelineService,
        RiskScoringService $riskScoringService,
        ProgressiveFrictionService $progressiveFrictionService
    ) {
        $this->fraudTimelineService = $fraudTimelineService;
        $this->riskScoringService = $riskScoringService;
        $this->progressiveFrictionService = $progressiveFrictionService;
    }
    
    /**
     * Get fraud analytics dashboard overview
     */
    public function getDashboardOverview(Request $request): JsonResponse
    {
        $timeRange = $request->get('time_range', '24h');
        
        // Generate comprehensive fraud analytics report
        $analyticsReport = $this->fraudTimelineService->generateFraudAnalyticsReport([
            'time_range' => $timeRange,
            'group_by' => 'hour'
        ]);
        
        if (!$analyticsReport['success']) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics report'
            ], 500);
        }
        
        // Detect current fraud patterns
        $patternDetection = $this->fraudTimelineService->detectFraudPatterns([
            'time_range' => $timeRange,
            'min_occurrences' => 3
        ]);
        
        return response()->json([
            'success' => true,
            'dashboard_data' => [
                'overview_metrics' => $analyticsReport['overall_metrics'],
                'risk_distribution' => $analyticsReport['risk_distribution'],
                'trend_analysis' => $analyticsReport['trend_analysis'],
                'pattern_detection' => $patternDetection['patterns'] ?? [],
                'pattern_summary' => $patternDetection['summary'] ?? [],
                'action_effectiveness' => $analyticsReport['action_effectiveness'],
                'top_risk_factors' => $analyticsReport['top_risk_factors'],
                'generated_at' => $analyticsReport['generated_at']
            ]
        ]);
    }
    
    /**
     * Get detailed fraud timeline for a specific booking
     */
    public function getBookingFraudTimeline(Request $request, int $bookingId): JsonResponse
    {
        $timeline = $this->fraudTimelineService->getBookingFraudTimeline($bookingId);
        
        if (!$timeline['success']) {
            return response()->json([
                'success' => false,
                'error' => $timeline['error']
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'fraud_timeline' => $timeline
        ]);
    }
    
    /**
     * Get fraud timeline for a customer across all bookings
     */
    public function getCustomerFraudTimeline(Request $request, int $customerId): JsonResponse
    {
        $limit = $request->get('limit', 100);
        
        $timeline = $this->fraudTimelineService->getCustomerFraudTimeline($customerId, $limit);
        
        if (!$timeline['success']) {
            return response()->json([
                'success' => false,
                'error' => $timeline['error']
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'customer_timeline' => $timeline
        ]);
    }
    
    /**
     * Detect and analyze fraud patterns
     */
    public function detectFraudPatterns(Request $request): JsonResponse
    {
        $filters = [
            'time_range' => $request->get('time_range', '24h'),
            'min_occurrences' => $request->get('min_occurrences', 3),
            'pattern_types' => $request->get('pattern_types', [])
        ];
        
        $patternDetection = $this->fraudTimelineService->detectFraudPatterns($filters);
        
        if (!$patternDetection['success']) {
            return response()->json([
                'success' => false,
                'error' => $patternDetection['error']
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'pattern_detection' => $patternDetection
        ]);
    }
    
    /**
     * Generate comprehensive fraud analytics report
     */
    public function generateAnalyticsReport(Request $request): JsonResponse
    {
        $parameters = [
            'time_range' => $request->get('time_range', '7d'),
            'group_by' => $request->get('group_by', 'day'),
            'include_details' => $request->get('include_details', false)
        ];
        
        $report = $this->fraudTimelineService->generateFraudAnalyticsReport($parameters);
        
        if (!$report['success']) {
            return response()->json([
                'success' => false,
                'error' => $report['error']
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'analytics_report' => $report
        ]);
    }
    
    /**
     * Calculate risk score for given context
     */
    public function calculateRiskScore(Request $request): JsonResponse
    {
        $context = $request->validate([
            'customer_id' => 'nullable|integer',
            'ip_address' => 'nullable|string',
            'session_id' => 'nullable|string',
            'device_fingerprint' => 'nullable|string',
            'transaction_amount' => 'nullable|numeric',
            'payment_method' => 'nullable|string',
            'tenant_id' => 'nullable|string'
        ]);
        
        $riskAssessment = $this->riskScoringService->calculateRiskScore($context);
        
        if (!$riskAssessment['success']) {
            return response()->json([
                'success' => false,
                'error' => $riskAssessment['error']
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'risk_assessment' => $riskAssessment
        ]);
    }
    
    /**
     * Apply progressive friction based on risk assessment
     */
    public function applyProgressiveFriction(Request $request): JsonResponse
    {
        $context = $request->validate([
            'customer_id' => 'nullable|integer',
            'booking_id' => 'nullable|integer',
            'ip_address' => 'nullable|string',
            'session_id' => 'nullable|string',
            'device_fingerprint' => 'nullable|string',
            'transaction_amount' => 'nullable|numeric',
            'payment_method' => 'nullable|string',
            'tenant_id' => 'nullable|string'
        ]);
        
        $frictionResult = $this->progressiveFrictionService->applyProgressiveFriction($context);
        
        if (!$frictionResult['success']) {
            return response()->json([
                'success' => false,
                'error' => $frictionResult['error']
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'friction_result' => $frictionResult
        ]);
    }
    
    /**
     * Process verification step completion
     */
    public function processVerificationStep(Request $request): JsonResponse
    {
        $verificationData = $request->validate([
            'type' => 'required|string|in:captcha,email_verification,phone_verification,identity_document,proof_of_address',
            'customer_id' => 'nullable|integer',
            'token' => 'nullable|string',
            'verification_code' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'document_type' => 'nullable|string',
            'document_image' => 'nullable|string',
            'provider' => 'nullable|string',
            'threshold' => 'nullable|numeric'
        ]);
        
        $verificationResult = $this->progressiveFrictionService->processVerificationStep($verificationData);
        
        if (!$verificationResult['success']) {
            return response()->json([
                'success' => false,
                'error' => $verificationResult['error']
            ], 400);
        }
        
        return response()->json([
            'success' => true,
            'verification_result' => $verificationResult
        ]);
    }
    
    /**
     * Create dispute resolution case
     */
    public function createDisputeCase(Request $request): JsonResponse
    {
        $disputeData = $request->validate([
            'booking_id' => 'required|integer',
            'customer_id' => 'required|integer',
            'dispute_type' => 'required|string|in:false_positive,unauthorized_transaction,service_issue,billing_dispute',
            'description' => 'required|string|max:1000',
            'supporting_evidence' => 'nullable|array'
        ]);
        
        $caseResult = $this->fraudTimelineService->createDisputeResolutionCase($disputeData);
        
        if (!$caseResult['success']) {
            return response()->json([
                'success' => false,
                'error' => $caseResult['error']
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'dispute_case' => $caseResult
        ]);
    }
    
    /**
     * Get fraud investigation cases
     */
    public function getInvestigationCases(Request $request): JsonResponse
    {
        $status = $request->get('status');
        $priority = $request->get('priority');
        $limit = $request->get('limit', 50);
        $offset = $request->get('offset', 0);
        
        $query = \DB::table('fraud_investigation_cases')
            ->select([
                'id',
                'case_number',
                'booking_id',
                'customer_id',
                'case_status',
                'priority',
                'initial_risk_score',
                'assigned_to_user_id',
                'opened_at',
                'assigned_at',
                'resolved_at',
                'created_at'
            ])
            ->orderBy('opened_at', 'desc');
            
        if ($status) {
            $query->where('case_status', $status);
        }
        
        if ($priority) {
            $query->where('priority', $priority);
        }
        
        $total = $query->count();
        $cases = $query->limit($limit)->offset($offset)->get();
        
        return response()->json([
            'success' => true,
            'investigation_cases' => [
                'cases' => $cases,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]
        ]);
    }
    
    /**
     * Get detailed investigation case
     */
    public function getInvestigationCase(Request $request, int $caseId): JsonResponse
    {
        $case = \DB::table('fraud_investigation_cases')
            ->where('id', $caseId)
            ->first();
            
        if (!$case) {
            return response()->json([
                'success' => false,
                'error' => 'Investigation case not found'
            ], 404);
        }
        
        // Get fraud timeline for the booking
        $timeline = $this->fraudTimelineService->getBookingFraudTimeline($case->booking_id);
        
        return response()->json([
            'success' => true,
            'investigation_case' => [
                'case_details' => $case,
                'evidence_data' => json_decode($case->evidence_data, true),
                'fraud_timeline' => $timeline['success'] ? $timeline : null
            ]
        ]);
    }
    
    /**
     * Update investigation case status
     */
    public function updateInvestigationCase(Request $request, int $caseId): JsonResponse
    {
        $updateData = $request->validate([
            'case_status' => 'nullable|string|in:open,under_review,pending_verification,resolved_fraud,resolved_legitimate,escalated,closed',
            'assigned_to_user_id' => 'nullable|integer',
            'investigation_notes' => 'nullable|string',
            'resolution_type' => 'nullable|string',
            'resolution_notes' => 'nullable|string'
        ]);
        
        $case = \DB::table('fraud_investigation_cases')
            ->where('id', $caseId)
            ->first();
            
        if (!$case) {
            return response()->json([
                'success' => false,
                'error' => 'Investigation case not found'
            ], 404);
        }
        
        // Prepare update data
        $updates = array_filter($updateData);
        $updates['updated_at'] = now();
        
        // Set timestamps based on status changes
        if (isset($updateData['assigned_to_user_id']) && !$case->assigned_at) {
            $updates['assigned_at'] = now();
        }
        
        if (isset($updateData['case_status']) && 
            in_array($updateData['case_status'], ['resolved_fraud', 'resolved_legitimate', 'closed']) &&
            !$case->resolved_at) {
            $updates['resolved_at'] = now();
        }
        
        \DB::table('fraud_investigation_cases')
            ->where('id', $caseId)
            ->update($updates);
            
        // Log case update to fraud timeline
        if ($case->booking_id) {
            \DB::table('fraud_timeline')->insert([
                'booking_id' => $case->booking_id,
                'customer_id' => $case->customer_id,
                'event_type' => 'manual_review',
                'event_data' => json_encode([
                    'case_id' => $caseId,
                    'case_number' => $case->case_number,
                    'status_change' => $updateData['case_status'] ?? null,
                    'update_data' => $updateData
                ]),
                'actor_type' => 'staff',
                'actor_id' => auth()->id() ?? 'system',
                'action_taken' => 'case_updated',
                'notes' => $updateData['investigation_notes'] ?? null,
                'event_timestamp' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Investigation case updated successfully'
        ]);
    }
    
    /**
     * Get fraud statistics summary
     */
    public function getFraudStatistics(Request $request): JsonResponse
    {
        $timeRange = $request->get('time_range', '7d');
        $startTime = $this->parseTimeRange($timeRange);
        
        $statistics = \DB::selectOne("
            SELECT 
                COUNT(DISTINCT ft.booking_id) as total_bookings_with_fraud_events,
                COUNT(ft.id) as total_fraud_events,
                COUNT(DISTINCT ft.customer_id) as unique_customers_with_events,
                AVG(ft.risk_score_at_time) as avg_risk_score,
                SUM(CASE WHEN ft.event_type = 'fraud_confirmed' THEN 1 ELSE 0 END) as confirmed_fraud_cases,
                SUM(CASE WHEN ft.risk_score_at_time >= 90 THEN 1 ELSE 0 END) as critical_risk_events,
                SUM(CASE WHEN ft.risk_score_at_time >= 70 THEN 1 ELSE 0 END) as high_risk_events,
                COUNT(DISTINCT fic.id) as open_investigation_cases
            FROM fraud_timeline ft
            LEFT JOIN fraud_investigation_cases fic ON fic.booking_id = ft.booking_id AND fic.case_status IN ('open', 'under_review', 'pending_verification')
            WHERE ft.event_timestamp >= ?
        ", [$startTime]);
        
        return response()->json([
            'success' => true,
            'fraud_statistics' => [
                'period' => [
                    'start_time' => $startTime->toISOString(),
                    'end_time' => now()->toISOString(),
                    'duration' => $timeRange
                ],
                'metrics' => $statistics,
                'calculated_at' => now()->toISOString()
            ]
        ]);
    }
    
    /**
     * Parse time range string to Carbon instance
     */
    private function parseTimeRange(string $timeRange): \Carbon\Carbon
    {
        $ranges = [
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            '90d' => now()->subDays(90)
        ];
        
        return $ranges[$timeRange] ?? now()->subDay();
    }
}