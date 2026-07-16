<?php

namespace Modules\EventManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProgressiveFrictionService - Task 8.3
 *
 * Implements progressive response actions based on risk levels with identity verification
 * escalation workflows, CAPTCHA, phone verification, document upload flows, and
 * risk-based rate limiting with cooling periods.
 *
 * Requirements: 24.2, 24.4
 */
class ProgressiveFrictionService
{
    protected AuditService $auditService;

    protected RiskScoringService $riskScoringService;

    // Cache configuration
    private const CACHE_PREFIX = 'friction:';

    private const RATE_LIMIT_CACHE_TTL = 3600; // 1 hour

    private const VERIFICATION_CACHE_TTL = 1800; // 30 minutes

    // Friction levels and actions
    private const FRICTION_LEVELS = [
        'very_low' => 0,
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'very_high' => 4,
        'critical' => 5,
    ];

    // Verification types
    private const VERIFICATION_TYPES = [
        'captcha' => 'CAPTCHA Challenge',
        'email' => 'Email Verification',
        'phone' => 'Phone Verification',
        'document' => 'Document Upload',
        'manual_review' => 'Manual Review',
        'cooling_period' => 'Cooling Period',
        'account_verification' => 'Account Verification',
    ];

    public function __construct(
        AuditService $auditService,
        RiskScoringService $riskScoringService
    ) {
        $this->auditService = $auditService;
        $this->riskScoringService = $riskScoringService;
    }

    /**
     * Apply progressive friction based on risk assessment.
     *
     * @param  array  $riskAssessment  Risk assessment result
     * @param  array  $context  Request context
     * @return array Friction response
     *
     * Requirements: 24.2, 24.4
     */
    public function applyProgressiveFriction(array $riskAssessment, array $context): array
    {
        try {
            $riskLevel = $riskAssessment['risk_level'];
            $tenantId = $this->resolveTenantId($context['tenant_id'] ?? null);

            // Check if user is in cooling period
            $coolingPeriodCheck = $this->checkCoolingPeriod($context);
            if (! $coolingPeriodCheck['allowed']) {
                return $coolingPeriodCheck;
            }

            // Check rate limits
            $rateLimitCheck = $this->checkRateLimits($riskLevel, $context);
            if (! $rateLimitCheck['allowed']) {
                return $rateLimitCheck;
            }

            // Get friction configuration for tenant and risk level
            $frictionConfig = $this->getFrictionConfiguration($tenantId, $riskLevel);

            // Determine required verifications
            $requiredVerifications = $this->determineRequiredVerifications($riskLevel, $context, $frictionConfig);

            // Check existing verifications
            $existingVerifications = $this->getExistingVerifications($context);

            // Determine missing verifications
            $missingVerifications = $this->getMissingVerifications($requiredVerifications, $existingVerifications);

            // Apply friction actions
            $frictionResponse = $this->buildFrictionResponse($riskLevel, $missingVerifications, $frictionConfig, $context);

            // Log friction application
            $this->logFrictionEvent($riskAssessment, $frictionResponse, $context);

            // Update rate limiting counters
            $this->updateRateLimitCounters($riskLevel, $context);

            return $frictionResponse;

        } catch (\Exception $e) {
            Log::error('Failed to apply progressive friction', [
                'error' => $e->getMessage(),
                'risk_assessment' => $riskAssessment,
                'context' => $context,
            ]);

            return [
                'allowed' => false,
                'friction_level' => 'critical',
                'error' => 'Friction system error',
                'required_actions' => ['manual_review'],
            ];
        }
    }

    /**
     * Process verification completion.
     *
     * @param  string  $verificationType  Verification type
     * @param  array  $verificationData  Verification data
     * @param  array  $context  Request context
     * @return array Verification result
     *
     * Requirements: 24.2, 24.4
     */
    public function processVerificationCompletion(string $verificationType, array $verificationData, array $context): array
    {
        try {
            // Validate verification data
            $validationResult = $this->validateVerification($verificationType, $verificationData, $context);

            if (! $validationResult['valid']) {
                return [
                    'success' => false,
                    'error' => $validationResult['error'],
                    'retry_allowed' => $validationResult['retry_allowed'] ?? true,
                ];
            }

            // Store verification record
            $verificationId = $this->storeVerificationRecord($verificationType, $verificationData, $context);

            // Update verification cache
            $this->updateVerificationCache($context, $verificationType, true);

            // Check if all required verifications are complete
            $completionStatus = $this->checkVerificationCompletion($context);

            // Log verification completion
            $this->auditService->log('verification_completed', [
                'verification_id' => $verificationId,
                'verification_type' => $verificationType,
                'customer_id' => $context['customer_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'completion_status' => $completionStatus,
            ]);

            return [
                'success' => true,
                'verification_id' => $verificationId,
                'verification_type' => $verificationType,
                'all_verifications_complete' => $completionStatus['complete'],
                'remaining_verifications' => $completionStatus['remaining'],
                'can_proceed' => $completionStatus['complete'],
            ];

        } catch (\Exception $e) {
            Log::error('Failed to process verification completion', [
                'error' => $e->getMessage(),
                'verification_type' => $verificationType,
                'context' => $context,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if user is in cooling period.
     *
     * @param  array  $context  Request context
     * @return array Cooling period check result
     *
     * Requirements: 24.2
     */
    public function checkCoolingPeriod(array $context): array
    {
        $trackingKey = $this->getTrackingKey($context);
        $cacheKey = self::CACHE_PREFIX."cooling:{$trackingKey}";

        $coolingPeriodEnd = Cache::get($cacheKey);

        if ($coolingPeriodEnd && Carbon::now()->lt(Carbon::parse($coolingPeriodEnd))) {
            $remainingTime = Carbon::now()->diffInSeconds(Carbon::parse($coolingPeriodEnd));

            return [
                'allowed' => false,
                'friction_level' => 'cooling_period',
                'reason' => 'Account in cooling period',
                'cooling_period_end' => $coolingPeriodEnd,
                'remaining_seconds' => $remainingTime,
                'required_actions' => ['wait'],
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Apply cooling period to user.
     *
     * @param  array  $context  Request context
     * @param  int  $durationMinutes  Cooling period duration in minutes
     * @return array Cooling period result
     *
     * Requirements: 24.2
     */
    public function applyCoolingPeriod(array $context, int $durationMinutes): array
    {
        $trackingKey = $this->getTrackingKey($context);
        $cacheKey = self::CACHE_PREFIX."cooling:{$trackingKey}";

        $coolingPeriodEnd = Carbon::now()->addMinutes($durationMinutes);
        Cache::put($cacheKey, $coolingPeriodEnd->toISOString(), $durationMinutes * 60);

        // Log cooling period application
        $this->auditService->log('cooling_period_applied', [
            'customer_id' => $context['customer_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'tracking_key' => $trackingKey,
            'duration_minutes' => $durationMinutes,
            'cooling_period_end' => $coolingPeriodEnd->toISOString(),
        ]);

        return [
            'success' => true,
            'cooling_period_end' => $coolingPeriodEnd->toISOString(),
            'duration_minutes' => $durationMinutes,
        ];
    }

    /**
     * Check rate limits based on risk level.
     *
     * @param  string  $riskLevel  Risk level
     * @param  array  $context  Request context
     * @return array Rate limit check result
     *
     * Requirements: 24.2
     */
    private function checkRateLimits(string $riskLevel, array $context): array
    {
        $trackingKey = $this->getTrackingKey($context);
        $rateLimits = $this->getRateLimits($riskLevel, $this->resolveTenantId($context['tenant_id'] ?? null));

        foreach ($rateLimits as $window => $limit) {
            $cacheKey = self::CACHE_PREFIX."rate_limit:{$trackingKey}:{$window}";
            $currentCount = Cache::get($cacheKey, 0);

            if ($currentCount >= $limit) {
                return [
                    'allowed' => false,
                    'friction_level' => 'rate_limited',
                    'reason' => "Rate limit exceeded for {$window}",
                    'limit' => $limit,
                    'current_count' => $currentCount,
                    'window' => $window,
                    'required_actions' => ['wait'],
                ];
            }
        }

        return ['allowed' => true];
    }

    /**
     * Get friction configuration for tenant and risk level.
     *
     * @param  string  $tenantId  Tenant ID
     * @param  string  $riskLevel  Risk level
     * @return array Friction configuration
     *
     * Requirements: 24.4
     */
    private function getFrictionConfiguration(string $tenantId, string $riskLevel): array
    {
        $cacheKey = self::CACHE_PREFIX."config:{$tenantId}:{$riskLevel}";
        $cachedConfig = Cache::get($cacheKey);

        if ($cachedConfig !== null) {
            return $cachedConfig;
        }

        // Get tenant configuration
        $tenantConfig = DB::table('tenant_risk_configurations')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if (! $tenantConfig) {
            // Fall back to default
            $tenantConfig = DB::table('tenant_risk_configurations')
                ->where('tenant_id', 'default')
                ->where('is_active', true)
                ->first();
        }

        $frictionSettings = $tenantConfig ? json_decode($tenantConfig->friction_settings, true) : [];
        $config = $frictionSettings[$riskLevel] ?? $this->getDefaultFrictionConfig($riskLevel);

        Cache::put($cacheKey, $config, self::VERIFICATION_CACHE_TTL);

        return $config;
    }

    /**
     * Determine required verifications based on risk level.
     *
     * @param  string  $riskLevel  Risk level
     * @param  array  $context  Request context
     * @param  array  $frictionConfig  Friction configuration
     * @return array Required verifications
     *
     * Requirements: 24.2
     */
    private function determineRequiredVerifications(string $riskLevel, array $context, array $frictionConfig): array
    {
        $verifications = [];

        switch ($riskLevel) {
            case 'very_low':
            case 'low':
                // No additional verification required
                break;

            case 'medium':
                $verifications[] = 'captcha';
                break;

            case 'high':
                $verifications[] = 'captcha';
                if (! isset($context['customer_id'])) {
                    $verifications[] = 'email';
                }
                break;

            case 'very_high':
                $verifications[] = 'captcha';
                $verifications[] = 'email';
                $verifications[] = 'phone';
                break;

            case 'critical':
                $verifications[] = 'captcha';
                $verifications[] = 'email';
                $verifications[] = 'phone';
                $verifications[] = 'document';
                $verifications[] = 'manual_review';
                break;
        }

        // Apply configuration overrides
        if (isset($frictionConfig['required_verifications'])) {
            $verifications = array_merge($verifications, $frictionConfig['required_verifications']);
        }

        return array_unique($verifications);
    }

    /**
     * Get existing verifications for context.
     *
     * @param  array  $context  Request context
     * @return array Existing verifications
     */
    private function getExistingVerifications(array $context): array
    {
        $trackingKey = $this->getTrackingKey($context);
        $cacheKey = self::CACHE_PREFIX."verifications:{$trackingKey}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Query database for existing verifications
        $verifications = DB::table('verification_records')
            ->where(function ($query) use ($context) {
                if (isset($context['customer_id'])) {
                    $query->where('customer_id', $context['customer_id']);
                }
                if (isset($context['session_id'])) {
                    $query->orWhere('session_id', $context['session_id']);
                }
                if (isset($context['ip_address'])) {
                    $query->orWhere('ip_address', $context['ip_address']);
                }
            })
            ->where('verified_at', '>', Carbon::now()->subHours(24)) // Valid for 24 hours
            ->where('verification_status', 'verified')
            ->pluck('verification_type')
            ->toArray();

        Cache::put($cacheKey, $verifications, self::VERIFICATION_CACHE_TTL);

        return $verifications;
    }

    /**
     * Get missing verifications.
     *
     * @param  array  $required  Required verifications
     * @param  array  $existing  Existing verifications
     * @return array Missing verifications
     */
    private function getMissingVerifications(array $required, array $existing): array
    {
        return array_diff($required, $existing);
    }

    /**
     * Build friction response.
     *
     * @param  string  $riskLevel  Risk level
     * @param  array  $missingVerifications  Missing verifications
     * @param  array  $frictionConfig  Friction configuration
     * @param  array  $context  Request context
     * @return array Friction response
     *
     * Requirements: 24.2, 24.4
     */
    private function buildFrictionResponse(string $riskLevel, array $missingVerifications, array $frictionConfig, array $context): array
    {
        if (empty($missingVerifications)) {
            return [
                'allowed' => true,
                'friction_level' => $riskLevel,
                'message' => 'All verifications complete',
                'required_actions' => [],
            ];
        }

        $frictionLevel = self::FRICTION_LEVELS[$riskLevel] ?? 0;

        // Determine if request should be blocked or allowed with friction
        $allowWithFriction = ! in_array('manual_review', $missingVerifications) && $frictionLevel < 5;

        $response = [
            'allowed' => $allowWithFriction,
            'friction_level' => $riskLevel,
            'friction_score' => $frictionLevel,
            'required_actions' => $missingVerifications,
            'verification_details' => $this->getVerificationDetails($missingVerifications),
            'message' => $this->getFrictionMessage($riskLevel, $missingVerifications),
        ];

        // Add cooling period if high risk
        if ($frictionLevel >= 4 && ! $allowWithFriction) {
            $coolingMinutes = $frictionConfig['cooling_period_minutes'] ?? 30;
            $this->applyCoolingPeriod($context, $coolingMinutes);
            $response['cooling_period_minutes'] = $coolingMinutes;
        }

        return $response;
    }

    /**
     * Get verification details for UI.
     *
     * @param  array  $verifications  Required verifications
     * @return array Verification details
     */
    private function getVerificationDetails(array $verifications): array
    {
        $details = [];

        foreach ($verifications as $verification) {
            $details[$verification] = [
                'type' => $verification,
                'name' => self::VERIFICATION_TYPES[$verification] ?? $verification,
                'description' => $this->getVerificationDescription($verification),
                'estimated_time' => $this->getVerificationEstimatedTime($verification),
            ];
        }

        return $details;
    }

    /**
     * Validate verification data.
     *
     * @param  string  $verificationType  Verification type
     * @param  array  $verificationData  Verification data
     * @param  array  $context  Request context
     * @return array Validation result
     */
    private function validateVerification(string $verificationType, array $verificationData, array $context): array
    {
        switch ($verificationType) {
            case 'captcha':
                return $this->validateCaptcha($verificationData, $context);

            case 'email':
                return $this->validateEmailVerification($verificationData, $context);

            case 'phone':
                return $this->validatePhoneVerification($verificationData, $context);

            case 'document':
                return $this->validateDocumentUpload($verificationData, $context);

            default:
                return [
                    'valid' => false,
                    'error' => 'Unknown verification type',
                    'retry_allowed' => false,
                ];
        }
    }

    /**
     * Store verification record.
     *
     * @param  string  $verificationType  Verification type
     * @param  array  $verificationData  Verification data
     * @param  array  $context  Request context
     * @return int Verification ID
     */
    private function storeVerificationRecord(string $verificationType, array $verificationData, array $context): int
    {
        return DB::table('verification_records')->insertGetId([
            'customer_id' => $context['customer_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'ip_address' => $context['ip_address'] ?? null,
            'verification_type' => $verificationType,
            'verification_data' => json_encode($verificationData),
            'verification_status' => 'verified',
            'verified_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHours(24),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    // Helper methods for specific verification types

    private function validateCaptcha(array $data, array $context): array
    {
        // Simplified CAPTCHA validation
        $response = $data['captcha_response'] ?? '';

        if (empty($response)) {
            return [
                'valid' => false,
                'error' => 'CAPTCHA response required',
                'retry_allowed' => true,
            ];
        }

        // In real implementation, validate with CAPTCHA service
        return [
            'valid' => true,
            'verification_method' => 'captcha',
        ];
    }

    private function validateEmailVerification(array $data, array $context): array
    {
        $code = $data['verification_code'] ?? '';
        $email = $data['email'] ?? '';

        if (empty($code) || empty($email)) {
            return [
                'valid' => false,
                'error' => 'Email and verification code required',
                'retry_allowed' => true,
            ];
        }

        // In real implementation, validate code against sent email
        return [
            'valid' => true,
            'verification_method' => 'email',
            'verified_email' => $email,
        ];
    }

    private function validatePhoneVerification(array $data, array $context): array
    {
        $code = $data['verification_code'] ?? '';
        $phone = $data['phone_number'] ?? '';

        if (empty($code) || empty($phone)) {
            return [
                'valid' => false,
                'error' => 'Phone number and verification code required',
                'retry_allowed' => true,
            ];
        }

        // In real implementation, validate SMS code
        return [
            'valid' => true,
            'verification_method' => 'phone',
            'verified_phone' => $phone,
        ];
    }

    private function validateDocumentUpload(array $data, array $context): array
    {
        $documentType = $data['document_type'] ?? '';
        $documentFile = $data['document_file'] ?? '';

        if (empty($documentType) || empty($documentFile)) {
            return [
                'valid' => false,
                'error' => 'Document type and file required',
                'retry_allowed' => true,
            ];
        }

        // In real implementation, validate document format and content
        return [
            'valid' => true,
            'verification_method' => 'document',
            'document_type' => $documentType,
        ];
    }

    // Helper methods

    private function getTrackingKey(array $context): string
    {
        if (isset($context['customer_id'])) {
            return "customer:{$context['customer_id']}";
        } elseif (isset($context['session_id'])) {
            return "session:{$context['session_id']}";
        } elseif (isset($context['ip_address'])) {
            return "ip:{$context['ip_address']}";
        } else {
            return 'unknown:'.uniqid();
        }
    }

    private function getRateLimits(string $riskLevel, string $tenantId): array
    {
        // Default rate limits based on risk level
        $limits = [
            'very_low' => ['1_minute' => 10, '1_hour' => 100],
            'low' => ['1_minute' => 8, '1_hour' => 80],
            'medium' => ['1_minute' => 5, '1_hour' => 50],
            'high' => ['1_minute' => 3, '1_hour' => 20],
            'very_high' => ['1_minute' => 2, '1_hour' => 10],
            'critical' => ['1_minute' => 1, '1_hour' => 5],
        ];

        return $limits[$riskLevel] ?? $limits['medium'];
    }

    private function getDefaultFrictionConfig(string $riskLevel): array
    {
        return [
            'required_verifications' => [],
            'cooling_period_minutes' => 30,
            'rate_limits' => $this->getRateLimits($riskLevel, 'default'),
        ];
    }

    private function getVerificationDescription(string $verification): string
    {
        $descriptions = [
            'captcha' => 'Complete the CAPTCHA challenge to verify you are human',
            'email' => 'Verify your email address by entering the code sent to your email',
            'phone' => 'Verify your phone number by entering the SMS code',
            'document' => 'Upload a government-issued ID for identity verification',
            'manual_review' => 'Your request requires manual review by our security team',
            'cooling_period' => 'Please wait before attempting another request',
        ];

        return $descriptions[$verification] ?? 'Additional verification required';
    }

    private function getVerificationEstimatedTime(string $verification): string
    {
        $times = [
            'captcha' => '1 minute',
            'email' => '2-5 minutes',
            'phone' => '2-5 minutes',
            'document' => '5-10 minutes',
            'manual_review' => '24-48 hours',
            'cooling_period' => '30 minutes',
        ];

        return $times[$verification] ?? 'Variable';
    }

    private function getFrictionMessage(string $riskLevel, array $missingVerifications): string
    {
        if (empty($missingVerifications)) {
            return 'Request approved';
        }

        $count = count($missingVerifications);

        if (in_array('manual_review', $missingVerifications)) {
            return 'Your request requires manual review for security purposes';
        }

        if ($count === 1) {
            return 'Additional verification required for security';
        }

        return "Multiple verifications required ({$count} steps)";
    }

    private function logFrictionEvent(array $riskAssessment, array $frictionResponse, array $context): void
    {
        $this->auditService->log('friction_applied', [
            'customer_id' => $context['customer_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'risk_score' => $riskAssessment['risk_score'] ?? null,
            'risk_level' => $riskAssessment['risk_level'] ?? null,
            'friction_level' => $frictionResponse['friction_level'] ?? null,
            'allowed' => $frictionResponse['allowed'] ?? false,
            'required_actions' => $frictionResponse['required_actions'] ?? [],
        ]);
    }

    private function updateRateLimitCounters(string $riskLevel, array $context): void
    {
        $trackingKey = $this->getTrackingKey($context);
        $windows = ['1_minute' => 60, '1_hour' => 3600];

        foreach ($windows as $window => $ttl) {
            $cacheKey = self::CACHE_PREFIX."rate_limit:{$trackingKey}:{$window}";
            $current = Cache::get($cacheKey, 0);
            Cache::put($cacheKey, $current + 1, $ttl);
        }
    }

    private function updateVerificationCache(array $context, string $verificationType, bool $verified): void
    {
        $trackingKey = $this->getTrackingKey($context);
        $cacheKey = self::CACHE_PREFIX."verifications:{$trackingKey}";

        $verifications = Cache::get($cacheKey, []);

        if ($verified && ! in_array($verificationType, $verifications)) {
            $verifications[] = $verificationType;
            Cache::put($cacheKey, $verifications, self::VERIFICATION_CACHE_TTL);
        }
    }

    private function resolveTenantId(?string $tenantId = null): string
    {
        $resolvedTenantId = $tenantId ?? tenant('id');

        if (! is_string($resolvedTenantId) || trim($resolvedTenantId) === '') {
            throw new \RuntimeException('Tenant context is required for progressive friction controls');
        }

        return $resolvedTenantId;
    }

    private function checkVerificationCompletion(array $context): array
    {
        // This would check if all required verifications are complete
        // Simplified implementation for now
        return [
            'complete' => true,
            'remaining' => [],
        ];
    }
}
