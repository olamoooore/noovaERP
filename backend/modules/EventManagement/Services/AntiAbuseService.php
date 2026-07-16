<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\Cache;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\BookingVelocityLog;
use Modules\EventManagement\Models\FraudAlert;
use Modules\EventManagement\Models\IpRestriction;
use Modules\EventManagement\Models\ManualReviewQueue;

class AntiAbuseService
{
    private FraudDetectionService $fraudDetectionService;

    public function __construct(FraudDetectionService $fraudDetectionService)
    {
        $this->fraudDetectionService = $fraudDetectionService;
    }

    /**
     * Enforce rate limiting for a customer or IP address.
     */
    public function enforceRateLimit(string $tenantId, array $context): array
    {
        $rateLimitKey = $this->generateRateLimitKey($tenantId, $context);
        $rateLimitConfig = $this->getRateLimitConfig($tenantId, $context);

        // Get current rate limit status
        $currentCount = Cache::get($rateLimitKey, 0);
        $isLimited = $currentCount >= $rateLimitConfig['max_attempts'];

        if ($isLimited) {
            // Apply rate limiting
            $this->applyRateLimitPenalty($tenantId, $context);

            return [
                'limited' => true,
                'current_count' => $currentCount,
                'max_attempts' => $rateLimitConfig['max_attempts'],
                'reset_time' => Cache::get($rateLimitKey.'_reset', now()->addMinutes($rateLimitConfig['window_minutes'])),
                'penalty_applied' => true,
            ];
        }

        // Increment counter
        $newCount = $currentCount + 1;
        Cache::put($rateLimitKey, $newCount, now()->addMinutes($rateLimitConfig['window_minutes']));

        if ($newCount === 1) {
            Cache::put($rateLimitKey.'_reset', now()->addMinutes($rateLimitConfig['window_minutes']), now()->addMinutes($rateLimitConfig['window_minutes']));
        }

        return [
            'limited' => false,
            'current_count' => $newCount,
            'max_attempts' => $rateLimitConfig['max_attempts'],
            'remaining_attempts' => $rateLimitConfig['max_attempts'] - $newCount,
        ];
    }

    /**
     * Block an IP address with automatic expiration.
     */
    public function blockIpAddress(string $tenantId, string $ipAddress, string $reason, int $durationHours = 24): IpRestriction
    {
        // Check if IP is already blocked
        $existingRestriction = IpRestriction::forTenant($tenantId)
            ->where('ip_address', $ipAddress)
            ->ofType(IpRestriction::TYPE_BLOCKED)
            ->active()
            ->first();

        if ($existingRestriction) {
            // Extend existing block
            $existingRestriction->extendExpiration($durationHours);
            $existingRestriction->recordViolation();

            return $existingRestriction;
        }

        // Create new IP block
        return IpRestriction::create([
            'tenant_id' => $tenantId,
            'ip_address' => $ipAddress,
            'restriction_type' => IpRestriction::TYPE_BLOCKED,
            'reason' => $reason,
            'violation_count' => 1,
            'first_violation_at' => now(),
            'last_violation_at' => now(),
            'expires_at' => now()->addHours($durationHours),
            'is_active' => true,
            'metadata' => [
                'auto_blocked' => true,
                'duration_hours' => $durationHours,
            ],
        ]);
    }

    /**
     * Add IP address to allowlist.
     */
    public function allowlistIpAddress(string $tenantId, string $ipAddress, string $reason): IpRestriction
    {
        return IpRestriction::create([
            'tenant_id' => $tenantId,
            'ip_address' => $ipAddress,
            'restriction_type' => IpRestriction::TYPE_ALLOWED,
            'reason' => $reason,
            'is_active' => true,
            'metadata' => [
                'allowlisted_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Flag IP address for monitoring.
     */
    public function flagIpAddress(string $tenantId, string $ipAddress, string $reason): IpRestriction
    {
        return IpRestriction::create([
            'tenant_id' => $tenantId,
            'ip_address' => $ipAddress,
            'restriction_type' => IpRestriction::TYPE_FLAGGED,
            'reason' => $reason,
            'is_active' => true,
            'metadata' => [
                'flagged_at' => now()->toISOString(),
                'monitoring_level' => 'enhanced',
            ],
        ]);
    }

    /**
     * Create manual review queue entry for suspicious booking.
     */
    public function queueForManualReview(string $tenantId, int $bookingId, array $riskFactors, float $riskScore): ManualReviewQueue
    {
        $booking = Booking::findOrFail($bookingId);

        // Determine review reason and priority
        $reviewReason = $this->determineReviewReason($riskFactors);
        $priority = $this->calculateReviewPriority($riskScore, $riskFactors);

        return ManualReviewQueue::create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'customer_id' => $booking->customer_id,
            'review_reason' => $reviewReason,
            'priority' => $priority,
            'status' => ManualReviewQueue::STATUS_PENDING,
            'risk_factors' => $riskFactors,
            'risk_score' => $riskScore,
        ]);
    }

    /**
     * Execute automated fraud response actions.
     */
    public function executeAutomatedResponse(string $tenantId, array $actions, array $context): array
    {
        $executedActions = [];

        foreach ($actions as $action) {
            try {
                switch ($action) {
                    case 'block_ip':
                        if (isset($context['ip_address'])) {
                            $restriction = $this->blockIpAddress(
                                $tenantId,
                                $context['ip_address'],
                                'Automated fraud response',
                                24
                            );
                            $executedActions[] = [
                                'action' => 'block_ip',
                                'success' => true,
                                'details' => ['restriction_id' => $restriction->id],
                            ];
                        }
                        break;

                    case 'flag_ip':
                        if (isset($context['ip_address'])) {
                            $restriction = $this->flagIpAddress(
                                $tenantId,
                                $context['ip_address'],
                                'Automated fraud detection'
                            );
                            $executedActions[] = [
                                'action' => 'flag_ip',
                                'success' => true,
                                'details' => ['restriction_id' => $restriction->id],
                            ];
                        }
                        break;

                    case 'rate_limit':
                        $rateLimitResult = $this->enforceRateLimit($tenantId, $context);
                        $executedActions[] = [
                            'action' => 'rate_limit',
                            'success' => true,
                            'details' => $rateLimitResult,
                        ];
                        break;

                    case 'manual_review':
                        if (isset($context['booking_id'])) {
                            $reviewEntry = $this->queueForManualReview(
                                $tenantId,
                                $context['booking_id'],
                                $context['risk_factors'] ?? [],
                                $context['risk_score'] ?? 0
                            );
                            $executedActions[] = [
                                'action' => 'manual_review',
                                'success' => true,
                                'details' => ['review_id' => $reviewEntry->id],
                            ];
                        }
                        break;

                    case 'alert':
                        // Alert is already created by FraudDetectionService
                        $executedActions[] = [
                            'action' => 'alert',
                            'success' => true,
                            'details' => ['alert_created' => true],
                        ];
                        break;
                }
            } catch (\Exception $e) {
                $executedActions[] = [
                    'action' => $action,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $executedActions;
    }

    /**
     * Process manual review queue and auto-approve low-risk items.
     */
    public function processManualReviewQueue(string $tenantId, int $limit = 50): array
    {
        $pendingReviews = ManualReviewQueue::forTenant($tenantId)
            ->pending()
            ->byPriority()
            ->limit($limit)
            ->get();

        $processed = [
            'auto_approved' => 0,
            'requires_human_review' => 0,
            'errors' => 0,
        ];

        foreach ($pendingReviews as $review) {
            try {
                if ($this->canAutoApprove($review)) {
                    $review->approve('Auto-approved: Low risk score and no critical factors');
                    $processed['auto_approved']++;
                } else {
                    // Keep in queue for human review
                    $processed['requires_human_review']++;
                }
            } catch (\Exception $e) {
                $processed['errors']++;
            }
        }

        return $processed;
    }

    /**
     * Clean up expired restrictions and old logs.
     */
    public function cleanupExpiredData(string $tenantId): array
    {
        $cleaned = [
            'expired_restrictions' => 0,
            'old_velocity_logs' => 0,
            'resolved_alerts' => 0,
        ];

        // Mark expired restrictions as inactive
        $expiredRestrictions = IpRestriction::forTenant($tenantId)
            ->expired()
            ->where('is_active', true)
            ->get();

        foreach ($expiredRestrictions as $restriction) {
            $restriction->update(['is_active' => false]);
            $cleaned['expired_restrictions']++;
        }

        // Clean up old velocity logs (older than 30 days)
        $oldLogs = BookingVelocityLog::forTenant($tenantId)
            ->where('created_at', '<', now()->subDays(30))
            ->delete();
        $cleaned['old_velocity_logs'] = $oldLogs;

        // Archive old resolved alerts (older than 90 days)
        $oldAlerts = FraudAlert::forTenant($tenantId)
            ->resolved()
            ->where('resolved_at', '<', now()->subDays(90))
            ->count();
        $cleaned['resolved_alerts'] = $oldAlerts;

        return $cleaned;
    }

    /**
     * Get fraud statistics for a tenant.
     */
    public function getFraudStatistics(string $tenantId, int $days = 30): array
    {
        $startDate = now()->subDays($days);

        return [
            'total_alerts' => FraudAlert::forTenant($tenantId)
                ->where('created_at', '>=', $startDate)
                ->count(),
            'high_severity_alerts' => FraudAlert::forTenant($tenantId)
                ->where('created_at', '>=', $startDate)
                ->whereIn('severity', [FraudAlert::SEVERITY_HIGH, FraudAlert::SEVERITY_CRITICAL])
                ->count(),
            'blocked_ips' => IpRestriction::forTenant($tenantId)
                ->ofType(IpRestriction::TYPE_BLOCKED)
                ->active()
                ->count(),
            'manual_reviews_pending' => ManualReviewQueue::forTenant($tenantId)
                ->pending()
                ->count(),
            'manual_reviews_completed' => ManualReviewQueue::forTenant($tenantId)
                ->completed()
                ->where('created_at', '>=', $startDate)
                ->count(),
            'booking_attempts' => BookingVelocityLog::forTenant($tenantId)
                ->where('created_at', '>=', $startDate)
                ->count(),
            'suspicious_attempts' => BookingVelocityLog::forTenant($tenantId)
                ->where('created_at', '>=', $startDate)
                ->ofType(BookingVelocityLog::ACTION_BOOKING_FAILED)
                ->count(),
        ];
    }

    /**
     * Generate rate limit cache key.
     */
    private function generateRateLimitKey(string $tenantId, array $context): string
    {
        if (isset($context['customer_id'])) {
            return "rate_limit:{$tenantId}:customer:{$context['customer_id']}";
        }

        if (isset($context['ip_address'])) {
            return "rate_limit:{$tenantId}:ip:".md5($context['ip_address']);
        }

        return "rate_limit:{$tenantId}:session:".($context['session_id'] ?? 'unknown');
    }

    /**
     * Get rate limit configuration based on context.
     */
    private function getRateLimitConfig(string $tenantId, array $context): array
    {
        // Default configuration - could be made configurable per tenant
        $defaultConfig = [
            'max_attempts' => 10,
            'window_minutes' => 60,
        ];

        // Adjust based on context
        if (isset($context['customer_id'])) {
            // Registered customers get higher limits
            $defaultConfig['max_attempts'] = 15;
        }

        if (isset($context['ip_address'])) {
            // Check if IP is flagged for stricter limits
            $restriction = IpRestriction::forTenant($tenantId)
                ->where('ip_address', $context['ip_address'])
                ->ofType(IpRestriction::TYPE_FLAGGED)
                ->active()
                ->first();

            if ($restriction) {
                $defaultConfig['max_attempts'] = 5; // Stricter limit for flagged IPs
            }
        }

        return $defaultConfig;
    }

    /**
     * Apply rate limit penalty (temporary IP restriction).
     */
    private function applyRateLimitPenalty(string $tenantId, array $context): void
    {
        if (isset($context['ip_address'])) {
            $this->blockIpAddress(
                $tenantId,
                $context['ip_address'],
                'Rate limit exceeded',
                1 // 1 hour block
            );
        }
    }

    /**
     * Determine review reason based on risk factors.
     */
    private function determineReviewReason(array $riskFactors): string
    {
        if (in_array('velocity_exceeded', $riskFactors)) {
            return ManualReviewQueue::REASON_VELOCITY_EXCEEDED;
        }

        if (in_array('suspicious_pattern', $riskFactors)) {
            return ManualReviewQueue::REASON_SUSPICIOUS_PATTERN;
        }

        if (in_array('high_risk_customer', $riskFactors)) {
            return ManualReviewQueue::REASON_HIGH_RISK_CUSTOMER;
        }

        return ManualReviewQueue::REASON_MANUAL_FLAG;
    }

    /**
     * Calculate review priority based on risk score and factors.
     */
    private function calculateReviewPriority(float $riskScore, array $riskFactors): string
    {
        if ($riskScore >= 90 || in_array('critical_threat', $riskFactors)) {
            return ManualReviewQueue::PRIORITY_URGENT;
        }

        if ($riskScore >= 70 || in_array('high_risk_customer', $riskFactors)) {
            return ManualReviewQueue::PRIORITY_HIGH;
        }

        if ($riskScore >= 40) {
            return ManualReviewQueue::PRIORITY_MEDIUM;
        }

        return ManualReviewQueue::PRIORITY_LOW;
    }

    /**
     * Check if a review can be auto-approved.
     */
    private function canAutoApprove(ManualReviewQueue $review): bool
    {
        // Auto-approve only very low risk items
        if ($review->risk_score > 30) {
            return false;
        }

        // Don't auto-approve if there are critical risk factors
        $criticalFactors = ['identity_verification_failed', 'blacklisted_ip', 'known_fraudster'];
        $riskFactors = $review->risk_factors ?? [];

        foreach ($criticalFactors as $factor) {
            if (in_array($factor, $riskFactors)) {
                return false;
            }
        }

        // Don't auto-approve high-priority items
        if (in_array($review->priority, [ManualReviewQueue::PRIORITY_HIGH, ManualReviewQueue::PRIORITY_URGENT])) {
            return false;
        }

        return true;
    }
}
