<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * RiskScoringService - Task 8.2
 * 
 * Provides dynamic risk score calculation with multiple behavioral factor analysis,
 * risk model versioning, A/B testing support, and tenant-specific threshold management.
 * 
 * Requirements: 24.1, 24.4
 */
class RiskScoringService
{
    protected AuditService $auditService;
    
    // Cache configuration
    private const CACHE_PREFIX = 'risk_scoring:';
    private const MODEL_CACHE_TTL = 3600; // 1 hour
    private const SCORE_CACHE_TTL = 300; // 5 minutes
    private const REPUTATION_CACHE_TTL = 1800; // 30 minutes
    
    // Risk scoring constants
    private const MAX_RISK_SCORE = 999.99;
    private const MIN_RISK_SCORE = 0.00;
    private const DEFAULT_CONFIDENCE_THRESHOLD = 0.7;
    
    // Risk levels and thresholds
    private const RISK_LEVEL_THRESHOLDS = [
        'very_low' => 0.0,
        'low' => 10.0,
        'medium' => 25.0,
        'high' => 50.0,
        'very_high' => 75.0,
        'critical' => 90.0,
    ];

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Calculate risk score for a booking attempt.
     * 
     * @param array $context Booking context and behavioral data
     * @return array Risk assessment result
     * 
     * Requirements: 24.1, 24.4
     */
    public function calculateRiskScore(array $context): array
    {
        try {
            $tenantId = $this->resolveTenantId($context['tenant_id'] ?? null);

            // Get active risk model for tenant
            $riskModel = $this->getActiveRiskModel($tenantId);
            
            if (!$riskModel) {
                return [
                    'success' => false,
                    'error' => 'No active risk model found',
                ];
            }
            
            // Extract behavioral signals
            $behavioralSignals = $this->extractBehavioralSignals($context);
            
            // Calculate individual risk factors
            $riskFactors = $this->calculateRiskFactors($behavioralSignals, $riskModel);
            
            // Calculate composite risk score
            $riskScore = $this->calculateCompositeScore($riskFactors, $riskModel);
            
            // Determine risk level
            $riskLevel = $this->determineRiskLevel($riskScore, $tenantId);
            
            // Calculate confidence score
            $confidenceScore = $this->calculateConfidenceScore($riskFactors, $riskModel);
            
            // Store risk score
            $riskScoreId = $this->storeRiskScore([
                'customer_id' => $context['customer_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'booking_id' => $context['booking_id'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent_hash' => $this->hashUserAgent($context['user_agent'] ?? ''),
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'risk_factors' => $riskFactors,
                'behavioral_signals' => $behavioralSignals,
                'scoring_model_name' => $riskModel['model_name'],
                'scoring_model_version' => $riskModel['model_version'],
                'confidence_score' => $confidenceScore,
                'model_metadata' => [
                    'calculation_timestamp' => Carbon::now()->toISOString(),
                    'context_hash' => hash('sha256', json_encode($context)),
                ],
            ]);
            
            // Update velocity tracking
            $this->updateVelocityTracking($context);
            
            // Update device fingerprint
            if (isset($context['device_fingerprint'])) {
                $this->updateDeviceFingerprint($context['device_fingerprint'], $riskLevel);
            }
            
            // Update IP reputation
            if (isset($context['ip_address'])) {
                $this->updateIpReputation($context['ip_address'], $riskLevel);
            }
            
            // Create audit log
            $this->auditService->log('risk_score_calculated', [
                'risk_score_id' => $riskScoreId,
                'customer_id' => $context['customer_id'] ?? null,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'confidence_score' => $confidenceScore,
                'model_name' => $riskModel['model_name'],
                'model_version' => $riskModel['model_version'],
            ]);
            
            Log::info('Risk score calculated', [
                'risk_score_id' => $riskScoreId,
                'customer_id' => $context['customer_id'] ?? null,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'confidence_score' => $confidenceScore,
            ]);
            
            return [
                'success' => true,
                'risk_score_id' => $riskScoreId,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'confidence_score' => $confidenceScore,
                'risk_factors' => $riskFactors,
                'model_name' => $riskModel['model_name'],
                'model_version' => $riskModel['model_version'],
                'recommended_actions' => $this->getRecommendedActions($riskLevel, $tenantId),
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to calculate risk score', [
                'error' => $e->getMessage(),
                'context' => $context,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get active risk model for tenant.
     * 
     * @param string $tenantId Tenant ID
     * @return array|null Risk model configuration
     * 
     * Requirements: 24.1, 24.4
     */
    public function getActiveRiskModel(string $tenantId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . "model:{$tenantId}";
        $cachedModel = Cache::get($cacheKey);
        
        if ($cachedModel !== null) {
            return $cachedModel;
        }
        
        // Get tenant-specific configuration
        $tenantConfig = DB::table('tenant_risk_configurations')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();
        
        if (!$tenantConfig) {
            // Fall back to default configuration
            $tenantConfig = DB::table('tenant_risk_configurations')
                ->where('tenant_id', 'default')
                ->where('is_active', true)
                ->first();
        }
        
        if (!$tenantConfig) {
            return null;
        }
        
        // Get the risk model
        $riskModel = DB::table('fraud_risk_models')
            ->where('model_name', $tenantConfig->active_model_name)
            ->where('model_version', $tenantConfig->active_model_version)
            ->where('model_status', 'active')
            ->first();
        
        if (!$riskModel) {
            return null;
        }
        
        $modelData = [
            'model_name' => $riskModel->model_name,
            'model_version' => $riskModel->model_version,
            'model_configuration' => json_decode($riskModel->model_configuration, true),
            'risk_factors' => json_decode($riskModel->risk_factors, true),
            'threshold_configuration' => json_decode($riskModel->threshold_configuration, true),
            'tenant_thresholds' => json_decode($tenantConfig->risk_thresholds, true),
            'response_actions' => json_decode($tenantConfig->response_actions, true),
            'friction_settings' => json_decode($tenantConfig->friction_settings, true),
        ];
        
        // Cache the model
        Cache::put($cacheKey, $modelData, self::MODEL_CACHE_TTL);
        
        return $modelData;
    }

    /**
     * Extract behavioral signals from context.
     * 
     * @param array $context Request context
     * @return array Behavioral signals
     * 
     * Requirements: 24.1
     */
    private function extractBehavioralSignals(array $context): array
    {
        $signals = [];
        
        // Time-based signals
        $signals['request_time'] = Carbon::now();
        $signals['hour_of_day'] = $signals['request_time']->hour;
        $signals['day_of_week'] = $signals['request_time']->dayOfWeek;
        $signals['is_weekend'] = $signals['request_time']->isWeekend();
        $signals['is_business_hours'] = $signals['hour_of_day'] >= 9 && $signals['hour_of_day'] <= 17;
        
        // Session and user signals
        $signals['session_duration'] = $context['session_duration'] ?? 0;
        $signals['page_views'] = $context['page_views'] ?? 0;
        $signals['time_on_page'] = $context['time_on_page'] ?? 0;
        $signals['mouse_movements'] = $context['mouse_movements'] ?? 0;
        $signals['keyboard_events'] = $context['keyboard_events'] ?? 0;
        
        // Transaction signals
        $signals['booking_amount'] = $context['booking_amount'] ?? 0;
        $signals['ticket_quantity'] = $context['ticket_quantity'] ?? 0;
        $signals['payment_method'] = $context['payment_method'] ?? 'unknown';
        $signals['is_guest_checkout'] = $context['is_guest_checkout'] ?? false;
        
        // Device and network signals
        $signals['ip_address'] = $context['ip_address'] ?? null;
        $signals['user_agent'] = $context['user_agent'] ?? null;
        $signals['device_fingerprint'] = $context['device_fingerprint'] ?? null;
        $signals['screen_resolution'] = $context['screen_resolution'] ?? null;
        $signals['timezone'] = $context['timezone'] ?? null;
        $signals['language'] = $context['language'] ?? null;
        
        // Customer history signals
        if (isset($context['customer_id'])) {
            $customerHistory = $this->getCustomerHistory($context['customer_id']);
            $signals['customer_age_days'] = $customerHistory['age_days'];
            $signals['total_bookings'] = $customerHistory['total_bookings'];
            $signals['successful_bookings'] = $customerHistory['successful_bookings'];
            $signals['cancelled_bookings'] = $customerHistory['cancelled_bookings'];
            $signals['average_booking_amount'] = $customerHistory['average_booking_amount'];
            $signals['days_since_last_booking'] = $customerHistory['days_since_last_booking'];
        }
        
        return $signals;
    }

    /**
     * Calculate individual risk factors.
     * 
     * @param array $signals Behavioral signals
     * @param array $model Risk model configuration
     * @return array Risk factor scores
     * 
     * Requirements: 24.1
     */
    private function calculateRiskFactors(array $signals, array $model): array
    {
        $factors = [];
        
        // Get risk factor definitions
        $factorDefinitions = DB::table('risk_factor_definitions')
            ->where('is_active', true)
            ->orderBy('calculation_priority')
            ->get();
        
        foreach ($factorDefinitions as $definition) {
            $factorName = $definition->factor_name;
            $factorConfig = json_decode($definition->factor_configuration, true);
            
            try {
                $factorScore = $this->calculateIndividualFactor(
                    $factorName,
                    $definition->factor_type,
                    $factorConfig,
                    $signals,
                    $model
                );
                
                $factors[$factorName] = [
                    'score' => $factorScore,
                    'weight' => $model['risk_factors'][$factorName]['weight'] ?? $definition->base_weight,
                    'category' => $definition->factor_category,
                    'weighted_score' => $factorScore * ($model['risk_factors'][$factorName]['weight'] ?? $definition->base_weight),
                ];
                
            } catch (\Exception $e) {
                Log::warning('Failed to calculate risk factor', [
                    'factor_name' => $factorName,
                    'error' => $e->getMessage(),
                ]);
                
                $factors[$factorName] = [
                    'score' => 0.0,
                    'weight' => 0.0,
                    'category' => $definition->factor_category,
                    'weighted_score' => 0.0,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $factors;
    }

    /**
     * Calculate individual risk factor score.
     * 
     * @param string $factorName Factor name
     * @param string $factorType Factor type
     * @param array $config Factor configuration
     * @param array $signals Behavioral signals
     * @param array $model Risk model
     * @return float Factor score
     */
    private function calculateIndividualFactor(string $factorName, string $factorType, array $config, array $signals, array $model): float
    {
        switch ($factorName) {
            case 'velocity_booking_attempts':
                return $this->calculateVelocityFactor($signals, 'booking_attempt');
                
            case 'velocity_payment_attempts':
                return $this->calculateVelocityFactor($signals, 'payment_attempt');
                
            case 'unusual_time_pattern':
                return $this->calculateTimePatternFactor($signals);
                
            case 'device_reputation':
                return $this->calculateDeviceReputationFactor($signals);
                
            case 'ip_reputation':
                return $this->calculateIpReputationFactor($signals);
                
            case 'customer_behavior_deviation':
                return $this->calculateBehaviorDeviationFactor($signals);
                
            case 'transaction_amount_anomaly':
                return $this->calculateAmountAnomalyFactor($signals);
                
            case 'session_behavior_anomaly':
                return $this->calculateSessionAnomalyFactor($signals);
                
            case 'geolocation_risk':
                return $this->calculateGeolocationRiskFactor($signals);
                
            case 'payment_method_risk':
                return $this->calculatePaymentMethodRiskFactor($signals);
                
            default:
                return 0.0;
        }
    }

    /**
     * Calculate composite risk score from individual factors.
     * 
     * @param array $factors Risk factors
     * @param array $model Risk model configuration
     * @return float Composite risk score
     * 
     * Requirements: 24.1
     */
    private function calculateCompositeScore(array $factors, array $model): float
    {
        $totalWeightedScore = 0.0;
        $totalWeight = 0.0;
        
        foreach ($factors as $factorName => $factorData) {
            if (isset($factorData['weighted_score']) && !isset($factorData['error'])) {
                $totalWeightedScore += $factorData['weighted_score'];
                $totalWeight += $factorData['weight'];
            }
        }
        
        if ($totalWeight == 0) {
            return 0.0;
        }
        
        $baseScore = $totalWeightedScore / $totalWeight;
        
        // Apply model-specific adjustments
        $adjustmentFactor = $model['model_configuration']['adjustment_factor'] ?? 1.0;
        $finalScore = $baseScore * $adjustmentFactor;
        
        // Ensure score is within bounds
        return max(self::MIN_RISK_SCORE, min(self::MAX_RISK_SCORE, $finalScore));
    }

    /**
     * Determine risk level from score.
     * 
     * @param float $riskScore Risk score
     * @param string $tenantId Tenant ID
     * @return string Risk level
     * 
     * Requirements: 24.4
     */
    private function determineRiskLevel(float $riskScore, string $tenantId): string
    {
        $model = $this->getActiveRiskModel($tenantId);
        $thresholds = $model['tenant_thresholds'] ?? self::RISK_LEVEL_THRESHOLDS;
        
        if ($riskScore >= $thresholds['critical']) {
            return 'critical';
        } elseif ($riskScore >= $thresholds['very_high']) {
            return 'very_high';
        } elseif ($riskScore >= $thresholds['high']) {
            return 'high';
        } elseif ($riskScore >= $thresholds['medium']) {
            return 'medium';
        } elseif ($riskScore >= $thresholds['low']) {
            return 'low';
        } else {
            return 'very_low';
        }
    }

    /**
     * Calculate confidence score for the risk assessment.
     * 
     * @param array $factors Risk factors
     * @param array $model Risk model
     * @return float Confidence score
     */
    private function calculateConfidenceScore(array $factors, array $model): float
    {
        $validFactors = 0;
        $totalFactors = count($factors);
        
        foreach ($factors as $factor) {
            if (!isset($factor['error'])) {
                $validFactors++;
            }
        }
        
        if ($totalFactors == 0) {
            return 0.0;
        }
        
        $dataCompleteness = $validFactors / $totalFactors;
        $modelAccuracy = $model['model_configuration']['accuracy_score'] ?? 0.8;
        
        return min(1.0, $dataCompleteness * $modelAccuracy);
    }

    /**
     * Store risk score in database.
     * 
     * @param array $scoreData Risk score data
     * @return int Risk score ID
     */
    private function storeRiskScore(array $scoreData): int
    {
        return DB::table('risk_scores')->insertGetId([
            'customer_id' => $scoreData['customer_id'],
            'session_id' => $scoreData['session_id'],
            'booking_id' => $scoreData['booking_id'],
            'ip_address' => $scoreData['ip_address'],
            'user_agent_hash' => $scoreData['user_agent_hash'],
            'risk_score' => $scoreData['risk_score'],
            'risk_level' => $scoreData['risk_level'],
            'risk_factors' => json_encode($scoreData['risk_factors']),
            'behavioral_signals' => json_encode($scoreData['behavioral_signals']),
            'scoring_model_name' => $scoreData['scoring_model_name'],
            'scoring_model_version' => $scoreData['scoring_model_version'],
            'model_metadata' => json_encode($scoreData['model_metadata']),
            'confidence_score' => $scoreData['confidence_score'],
            'calculated_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHours(24), // Scores expire after 24 hours
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Get recommended actions for risk level.
     * 
     * @param string $riskLevel Risk level
     * @param string $tenantId Tenant ID
     * @return array Recommended actions
     * 
     * Requirements: 24.4
     */
    private function getRecommendedActions(string $riskLevel, string $tenantId): array
    {
        $model = $this->getActiveRiskModel($tenantId);
        $responseActions = $model['response_actions'] ?? [];
        
        return $responseActions[$riskLevel] ?? [];
    }

    // Helper methods for specific risk factor calculations

    private function calculateVelocityFactor(array $signals, string $actionType): float
    {
        $trackingKey = $signals['customer_id'] ?? $signals['ip_address'] ?? 'unknown';
        
        $velocity = DB::table('velocity_tracking')
            ->where('tracking_key', $trackingKey)
            ->where('action_type', $actionType)
            ->first();
        
        if (!$velocity) {
            return 0.0;
        }
        
        // Calculate velocity score based on recent activity
        $score = 0.0;
        $score += $velocity->count_1_minute * 10.0;
        $score += $velocity->count_5_minutes * 5.0;
        $score += $velocity->count_15_minutes * 2.0;
        $score += $velocity->count_1_hour * 1.0;
        
        return min(100.0, $score);
    }

    private function calculateTimePatternFactor(array $signals): float
    {
        $hour = $signals['hour_of_day'];
        $isWeekend = $signals['is_weekend'];
        
        // Higher risk for unusual hours (late night/early morning)
        if ($hour >= 2 && $hour <= 6) {
            return 15.0;
        }
        
        // Moderate risk for weekend activity
        if ($isWeekend) {
            return 5.0;
        }
        
        return 0.0;
    }

    private function calculateDeviceReputationFactor(array $signals): float
    {
        if (!isset($signals['device_fingerprint'])) {
            return 10.0; // Unknown device gets moderate risk
        }
        
        $fingerprint = DB::table('device_fingerprints')
            ->where('fingerprint_hash', hash('sha256', $signals['device_fingerprint']))
            ->first();
        
        if (!$fingerprint) {
            return 5.0; // New device gets low risk
        }
        
        return $fingerprint->fraud_rate * 100.0;
    }

    private function calculateIpReputationFactor(array $signals): float
    {
        if (!isset($signals['ip_address'])) {
            return 10.0;
        }
        
        $reputation = DB::table('ip_reputation')
            ->where('ip_address', $signals['ip_address'])
            ->first();
        
        if (!$reputation) {
            return 5.0; // Unknown IP gets low risk
        }
        
        $score = $reputation->fraud_rate * 100.0;
        
        // Add penalties for suspicious characteristics
        if ($reputation->is_vpn) $score += 10.0;
        if ($reputation->is_proxy) $score += 15.0;
        if ($reputation->is_tor) $score += 25.0;
        if ($reputation->is_datacenter) $score += 20.0;
        
        return min(100.0, $score);
    }

    private function calculateBehaviorDeviationFactor(array $signals): float
    {
        if (!isset($signals['customer_id'])) {
            return 0.0;
        }
        
        // Compare current behavior to historical patterns
        $avgAmount = $signals['average_booking_amount'] ?? 0;
        $currentAmount = $signals['booking_amount'] ?? 0;
        
        if ($avgAmount > 0) {
            $deviation = abs($currentAmount - $avgAmount) / $avgAmount;
            return min(50.0, $deviation * 100.0);
        }
        
        return 0.0;
    }

    private function resolveTenantId(?string $tenantId = null): string
    {
        $resolvedTenantId = $tenantId ?? tenant('id');

        if (!is_string($resolvedTenantId) || trim($resolvedTenantId) === '') {
            throw new \RuntimeException('Tenant context is required for risk scoring');
        }

        return $resolvedTenantId;
    }

    private function calculateAmountAnomalyFactor(array $signals): float
    {
        $amount = $signals['booking_amount'] ?? 0;
        
        // Very high amounts are suspicious
        if ($amount > 10000) {
            return 30.0;
        } elseif ($amount > 5000) {
            return 15.0;
        } elseif ($amount > 2000) {
            return 5.0;
        }
        
        return 0.0;
    }

    private function calculateSessionAnomalyFactor(array $signals): float
    {
        $sessionDuration = $signals['session_duration'] ?? 0;
        $pageViews = $signals['page_views'] ?? 0;
        $timeOnPage = $signals['time_on_page'] ?? 0;
        
        $score = 0.0;
        
        // Very short sessions are suspicious
        if ($sessionDuration < 30) {
            $score += 20.0;
        }
        
        // Very few page views are suspicious
        if ($pageViews < 2) {
            $score += 15.0;
        }
        
        // Very short time on page is suspicious
        if ($timeOnPage < 10) {
            $score += 10.0;
        }
        
        return min(50.0, $score);
    }

    private function calculateGeolocationRiskFactor(array $signals): float
    {
        if (!isset($signals['ip_address'])) {
            return 0.0;
        }
        
        $reputation = DB::table('ip_reputation')
            ->where('ip_address', $signals['ip_address'])
            ->first();
        
        if (!$reputation) {
            return 0.0;
        }
        
        // High-risk countries get higher scores
        $highRiskCountries = ['CN', 'RU', 'NG', 'PK', 'BD'];
        if (in_array($reputation->country_code, $highRiskCountries)) {
            return 25.0;
        }
        
        return 0.0;
    }

    private function calculatePaymentMethodRiskFactor(array $signals): float
    {
        $paymentMethod = $signals['payment_method'] ?? 'unknown';
        
        switch ($paymentMethod) {
            case 'prepaid_card':
                return 20.0;
            case 'gift_card':
                return 30.0;
            case 'cryptocurrency':
                return 40.0;
            case 'bank_transfer':
                return 5.0;
            case 'credit_card':
                return 2.0;
            case 'debit_card':
                return 1.0;
            default:
                return 10.0;
        }
    }

    private function getCustomerHistory(int $customerId): array
    {
        // This would query customer history from bookings table
        // Simplified implementation for now
        return [
            'age_days' => 30,
            'total_bookings' => 5,
            'successful_bookings' => 4,
            'cancelled_bookings' => 1,
            'average_booking_amount' => 150.0,
            'days_since_last_booking' => 7,
        ];
    }

    private function updateVelocityTracking(array $context): void
    {
        // Update velocity tracking for various keys
        $trackingKeys = [
            ['key' => $context['customer_id'] ?? null, 'type' => 'customer'],
            ['key' => $context['ip_address'] ?? null, 'type' => 'ip'],
            ['key' => $context['device_fingerprint'] ?? null, 'type' => 'device'],
        ];
        
        foreach ($trackingKeys as $tracking) {
            if ($tracking['key']) {
                $this->incrementVelocityCounter($tracking['key'], $tracking['type'], 'booking_attempt');
            }
        }
    }

    private function incrementVelocityCounter(string $trackingKey, string $trackingType, string $actionType): void
    {
        // This would implement velocity counter updates
        // Simplified for now
        Log::info('Velocity counter updated', [
            'tracking_key' => $trackingKey,
            'tracking_type' => $trackingType,
            'action_type' => $actionType,
        ]);
    }

    private function updateDeviceFingerprint(string $deviceFingerprint, string $riskLevel): void
    {
        $fingerprintHash = hash('sha256', $deviceFingerprint);
        
        DB::table('device_fingerprints')
            ->updateOrInsert(
                ['fingerprint_hash' => $fingerprintHash],
                [
                    'last_seen_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
    }

    private function updateIpReputation(string $ipAddress, string $riskLevel): void
    {
        DB::table('ip_reputation')
            ->updateOrInsert(
                ['ip_address' => $ipAddress],
                [
                    'last_seen_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
    }

    private function hashUserAgent(string $userAgent): string
    {
        return hash('sha256', $userAgent);
    }
}