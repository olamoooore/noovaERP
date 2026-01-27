<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Models\BookingVelocityLog;
use Modules\EventManagement\Models\FraudDetectionRule;
use Modules\EventManagement\Models\FraudAlert;
use Modules\EventManagement\Models\IpRestriction;
use Modules\EventManagement\Models\IdentityVerification;
use Modules\EventManagement\Models\ManualReviewQueue;

class FraudDetectionService
{
    /**
     * Analyze a booking attempt for fraud indicators.
     */
    public function analyzeBookingAttempt(array $bookingData): array
    {
        $tenantId = $bookingData['tenant_id'] ?? 'default';
        
        // Log the booking attempt
        $this->logBookingVelocity($bookingData);
        
        // Get active fraud detection rules
        $rules = FraudDetectionRule::forTenant($tenantId)
            ->active()
            ->byPriority()
            ->get();

        $riskScore = 0;
        $triggeredRules = [];
        $evidence = [];
        $recommendedActions = [];

        // Evaluate each rule
        foreach ($rules as $rule) {
            if ($rule->shouldEvaluate($bookingData)) {
                $result = $rule->evaluate($bookingData);
                
                if ($result['triggered']) {
                    $triggeredRules[] = $rule;
                    $riskScore += $result['risk_score'];
                    $evidence = array_merge($evidence, $result['evidence']);
                    $recommendedActions = array_merge($recommendedActions, $result['recommended_actions']);
                    
                    // Create fraud alert
                    $this->createFraudAlert($rule, $bookingData, $result);
                }
            }
        }

        // Normalize risk score (0-100)
        $riskScore = min(100, $riskScore);
        
        // Determine final recommendation
        $recommendation = $this->determineRecommendation($riskScore, $recommendedActions);

        return [
            'risk_score' => $riskScore,
            'recommendation' => $recommendation,
            'triggered_rules' => $triggeredRules->pluck('rule_name')->toArray(),
            'evidence' => array_unique($evidence),
            'actions_required' => array_unique($recommendedActions),
        ];
    }

    /**
     * Check if an IP address is restricted.
     */
    public function checkIpRestriction(string $tenantId, string $ipAddress): array
    {
        $restriction = IpRestriction::forTenant($tenantId)
            ->active()
            ->get()
            ->first(function ($restriction) use ($ipAddress) {
                return $restriction->matchesIp($ipAddress);
            });

        if (!$restriction) {
            return [
                'restricted' => false,
                'restriction_type' => null,
                'reason' => null,
            ];
        }

        // Record violation if blocked or flagged
        if (in_array($restriction->restriction_type, [IpRestriction::TYPE_BLOCKED, IpRestriction::TYPE_FLAGGED])) {
            $restriction->recordViolation();
        }

        return [
            'restricted' => true,
            'restriction_type' => $restriction->restriction_type,
            'reason' => $restriction->reason,
            'expires_at' => $restriction->expires_at,
        ];
    }

    /**
     * Monitor booking velocity for a customer.
     */
    public function monitorCustomerVelocity(string $tenantId, int $customerId, int $timeWindowMinutes = 60): array
    {
        $recentAttempts = BookingVelocityLog::forTenant($tenantId)
            ->forCustomer($customerId)
            ->withinTimeWindow($timeWindowMinutes)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalAttempts = $recentAttempts->count();
        $successfulBookings = $recentAttempts->where('action_type', BookingVelocityLog::ACTION_BOOKING_SUCCESS)->count();
        $failedAttempts = $recentAttempts->where('action_type', BookingVelocityLog::ACTION_BOOKING_FAILED)->count();
        $totalTicketsRequested = $recentAttempts->sum('tickets_requested');

        // Calculate velocity metrics
        $successRate = $totalAttempts > 0 ? ($successfulBookings / $totalAttempts) * 100 : 0;
        $failureRate = $totalAttempts > 0 ? ($failedAttempts / $totalAttempts) * 100 : 0;

        // Determine if velocity is suspicious
        $isSuspicious = $this->isVelocitySuspicious([
            'total_attempts' => $totalAttempts,
            'failed_attempts' => $failedAttempts,
            'total_tickets' => $totalTicketsRequested,
            'time_window' => $timeWindowMinutes,
        ]);

        return [
            'total_attempts' => $totalAttempts,
            'successful_bookings' => $successfulBookings,
            'failed_attempts' => $failedAttempts,
            'total_tickets_requested' => $totalTicketsRequested,
            'success_rate' => round($successRate, 2),
            'failure_rate' => round($failureRate, 2),
            'is_suspicious' => $isSuspicious,
            'time_window_minutes' => $timeWindowMinutes,
        ];
    }

    /**
     * Monitor booking velocity for an IP address.
     */
    public function monitorIpVelocity(string $tenantId, string $ipAddress, int $timeWindowMinutes = 60): array
    {
        $recentAttempts = BookingVelocityLog::forTenant($tenantId)
            ->forIpAddress($ipAddress)
            ->withinTimeWindow($timeWindowMinutes)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalAttempts = $recentAttempts->count();
        $uniqueCustomers = $recentAttempts->whereNotNull('customer_id')->pluck('customer_id')->unique()->count();
        $uniqueSessions = $recentAttempts->whereNotNull('session_id')->pluck('session_id')->unique()->count();
        $totalTicketsRequested = $recentAttempts->sum('tickets_requested');

        // Calculate IP-specific metrics
        $customersPerSession = $uniqueSessions > 0 ? $uniqueCustomers / $uniqueSessions : 0;
        $attemptsPerCustomer = $uniqueCustomers > 0 ? $totalAttempts / $uniqueCustomers : $totalAttempts;

        // Determine if IP behavior is suspicious
        $isSuspicious = $this->isIpBehaviorSuspicious([
            'total_attempts' => $totalAttempts,
            'unique_customers' => $uniqueCustomers,
            'unique_sessions' => $uniqueSessions,
            'customers_per_session' => $customersPerSession,
            'attempts_per_customer' => $attemptsPerCustomer,
            'total_tickets' => $totalTicketsRequested,
        ]);

        return [
            'total_attempts' => $totalAttempts,
            'unique_customers' => $uniqueCustomers,
            'unique_sessions' => $uniqueSessions,
            'customers_per_session' => round($customersPerSession, 2),
            'attempts_per_customer' => round($attemptsPerCustomer, 2),
            'total_tickets_requested' => $totalTicketsRequested,
            'is_suspicious' => $isSuspicious,
            'time_window_minutes' => $timeWindowMinutes,
        ];
    }

    /**
     * Detect suspicious patterns in booking behavior.
     */
    public function detectSuspiciousPatterns(string $tenantId, array $bookingData): array
    {
        $patterns = [];

        // Pattern 1: Rapid-fire booking attempts
        if (isset($bookingData['customer_id'])) {
            $recentAttempts = BookingVelocityLog::forTenant($tenantId)
                ->forCustomer($bookingData['customer_id'])
                ->withinTimeWindow(5) // Last 5 minutes
                ->count();

            if ($recentAttempts >= 5) {
                $patterns[] = [
                    'type' => 'rapid_fire_attempts',
                    'description' => "Customer made {$recentAttempts} booking attempts in 5 minutes",
                    'risk_score' => min(50, $recentAttempts * 5),
                ];
            }
        }

        // Pattern 2: Unusual ticket quantities
        if (isset($bookingData['tickets_requested']) && $bookingData['tickets_requested'] > 8) {
            $patterns[] = [
                'type' => 'high_ticket_quantity',
                'description' => "Requesting {$bookingData['tickets_requested']} tickets (above normal threshold)",
                'risk_score' => min(40, ($bookingData['tickets_requested'] - 8) * 5),
            ];
        }

        // Pattern 3: Multiple events in short timeframe
        if (isset($bookingData['customer_id'])) {
            $uniqueEvents = BookingVelocityLog::forTenant($tenantId)
                ->forCustomer($bookingData['customer_id'])
                ->withinTimeWindow(60)
                ->pluck('event_id')
                ->unique()
                ->count();

            if ($uniqueEvents >= 3) {
                $patterns[] = [
                    'type' => 'multiple_events',
                    'description' => "Customer attempting to book {$uniqueEvents} different events within 1 hour",
                    'risk_score' => min(30, $uniqueEvents * 8),
                ];
            }
        }

        // Pattern 4: Suspicious user agent patterns
        if (isset($bookingData['user_agent'])) {
            if ($this->isSuspiciousUserAgent($bookingData['user_agent'])) {
                $patterns[] = [
                    'type' => 'suspicious_user_agent',
                    'description' => 'User agent indicates potential bot activity',
                    'risk_score' => 35,
                ];
            }
        }

        return $patterns;
    }

    /**
     * Check if identity verification is required.
     */
    public function requiresIdentityVerification(string $tenantId, int $customerId, float $riskScore): bool
    {
        // Check if customer already has sufficient verification
        $verification = IdentityVerification::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->where('verification_status', 'verified')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($verification && $verification->verification_level === 'full') {
            return false; // Already fully verified
        }

        // Determine verification requirement based on risk score
        if ($riskScore >= 80) {
            return true; // High risk requires verification
        }

        if ($riskScore >= 60 && (!$verification || $verification->verification_level === 'none')) {
            return true; // Medium-high risk requires at least basic verification
        }

        // Check for other risk factors
        $recentFailedAttempts = BookingVelocityLog::forTenant($tenantId)
            ->forCustomer($customerId)
            ->ofType(BookingVelocityLog::ACTION_BOOKING_FAILED)
            ->withinTimeWindow(24 * 60) // Last 24 hours
            ->count();

        if ($recentFailedAttempts >= 3) {
            return true; // Multiple failed attempts require verification
        }

        return false;
    }

    /**
     * Create a fraud alert.
     */
    private function createFraudAlert(FraudDetectionRule $rule, array $bookingData, array $result): FraudAlert
    {
        $severity = $this->calculateAlertSeverity($result['risk_score']);
        $entityType = $this->determineEntityType($bookingData);
        $entityId = $this->getEntityId($bookingData, $entityType);

        return FraudAlert::create([
            'tenant_id' => $bookingData['tenant_id'] ?? 'default',
            'fraud_detection_rule_id' => $rule->id,
            'alert_type' => $rule->rule_type,
            'severity' => $severity,
            'status' => FraudAlert::STATUS_OPEN,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => "Fraud rule '{$rule->rule_name}' triggered",
            'evidence' => $result['evidence'],
            'actions_taken' => [],
        ]);
    }

    /**
     * Log booking velocity data.
     */
    private function logBookingVelocity(array $bookingData): void
    {
        BookingVelocityLog::create([
            'tenant_id' => $bookingData['tenant_id'] ?? 'default',
            'customer_id' => $bookingData['customer_id'] ?? null,
            'ip_address' => $bookingData['ip_address'] ?? '0.0.0.0',
            'user_agent' => $bookingData['user_agent'] ?? null,
            'event_id' => $bookingData['event_id'],
            'tickets_requested' => $bookingData['tickets_requested'] ?? 1,
            'action_type' => $bookingData['action_type'] ?? BookingVelocityLog::ACTION_BOOKING_ATTEMPT,
            'session_id' => $bookingData['session_id'] ?? null,
            'request_metadata' => $bookingData['metadata'] ?? [],
            'created_at' => now(),
        ]);
    }

    /**
     * Determine if velocity is suspicious.
     */
    private function isVelocitySuspicious(array $metrics): bool
    {
        // More than 10 attempts in an hour
        if ($metrics['total_attempts'] > 10 && $metrics['time_window'] <= 60) {
            return true;
        }

        // High failure rate with many attempts
        if ($metrics['total_attempts'] >= 5 && $metrics['failed_attempts'] / $metrics['total_attempts'] > 0.7) {
            return true;
        }

        // Requesting too many tickets
        if ($metrics['total_tickets'] > 50) {
            return true;
        }

        return false;
    }

    /**
     * Determine if IP behavior is suspicious.
     */
    private function isIpBehaviorSuspicious(array $metrics): bool
    {
        // Too many attempts from single IP
        if ($metrics['total_attempts'] > 20) {
            return true;
        }

        // Multiple customers from same IP in short time
        if ($metrics['unique_customers'] > 5 && $metrics['customers_per_session'] > 2) {
            return true;
        }

        // High attempts per customer ratio
        if ($metrics['attempts_per_customer'] > 8) {
            return true;
        }

        return false;
    }

    /**
     * Check if user agent is suspicious.
     */
    private function isSuspiciousUserAgent(string $userAgent): bool
    {
        $suspiciousPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python', 'java',
            'automated', 'script', 'headless', 'phantom', 'selenium'
        ];

        $userAgentLower = strtolower($userAgent);
        
        foreach ($suspiciousPatterns as $pattern) {
            if (strpos($userAgentLower, $pattern) !== false) {
                return true;
            }
        }

        // Check for very short or very long user agents
        if (strlen($userAgent) < 20 || strlen($userAgent) > 500) {
            return true;
        }

        return false;
    }

    /**
     * Determine recommendation based on risk score and actions.
     */
    private function determineRecommendation(float $riskScore, array $actions): string
    {
        if ($riskScore >= 90 || in_array(FraudDetectionRule::ACTION_BLOCK, $actions)) {
            return 'block';
        }

        if ($riskScore >= 70 || in_array(FraudDetectionRule::ACTION_MANUAL_REVIEW, $actions)) {
            return 'manual_review';
        }

        if ($riskScore >= 50 || in_array(FraudDetectionRule::ACTION_IDENTITY_VERIFICATION, $actions)) {
            return 'identity_verification';
        }

        if ($riskScore >= 30 || in_array(FraudDetectionRule::ACTION_FLAG, $actions)) {
            return 'flag';
        }

        return 'allow';
    }

    /**
     * Calculate alert severity based on risk score.
     */
    private function calculateAlertSeverity(float $riskScore): string
    {
        if ($riskScore >= 90) {
            return FraudAlert::SEVERITY_CRITICAL;
        } elseif ($riskScore >= 70) {
            return FraudAlert::SEVERITY_HIGH;
        } elseif ($riskScore >= 40) {
            return FraudAlert::SEVERITY_MEDIUM;
        }
        
        return FraudAlert::SEVERITY_LOW;
    }

    /**
     * Determine entity type from booking data.
     */
    private function determineEntityType(array $bookingData): string
    {
        if (isset($bookingData['customer_id'])) {
            return FraudAlert::ENTITY_CUSTOMER;
        }
        
        if (isset($bookingData['ip_address'])) {
            return FraudAlert::ENTITY_IP_ADDRESS;
        }
        
        return FraudAlert::ENTITY_SESSION;
    }

    /**
     * Get entity ID based on type.
     */
    private function getEntityId(array $bookingData, string $entityType): string
    {
        switch ($entityType) {
            case FraudAlert::ENTITY_CUSTOMER:
                return (string) $bookingData['customer_id'];
            case FraudAlert::ENTITY_IP_ADDRESS:
                return $bookingData['ip_address'];
            case FraudAlert::ENTITY_SESSION:
                return $bookingData['session_id'] ?? 'unknown';
            default:
                return 'unknown';
        }
    }
}