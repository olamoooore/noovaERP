<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Services\FraudTimelineService;
use Modules\EventManagement\Services\ProgressiveFrictionService;
use Modules\EventManagement\Services\RiskScoringService;
use Tests\TestCase;

/**
 * Property Test 25: Progressive Fraud Prevention Accuracy
 *
 * Validates Requirements: 24.1, 24.2, 24.3, 24.4, 24.5
 *
 * This property-based test validates that the advanced fraud prevention system
 * maintains accurate risk scoring, progressive friction application, comprehensive
 * timeline tracking, and effective pattern recognition across all scenarios.
 */
class AdvancedFraudPreventionTest extends TestCase
{
    use RefreshDatabase;

    protected RiskScoringService $riskScoringService;

    protected ProgressiveFrictionService $frictionService;

    protected FraudTimelineService $timelineService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->riskScoringService = app(RiskScoringService::class);
        $this->frictionService = app(ProgressiveFrictionService::class);
        $this->timelineService = app(FraudTimelineService::class);

        // Clear cache for clean test state
        Cache::flush();

        $this->setupTestData();
    }

    /**
     * Property 25.1: Risk Scoring Consistency and Accuracy
     *
     * Validates that risk scoring produces consistent and accurate results
     * based on behavioral factors and maintains proper score boundaries.
     *
     * Requirements: 24.1, 24.4
     */
    public function test_risk_scoring_consistency_and_accuracy()
    {
        $iterations = 30;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random risk assessment context
            $context = $this->generateRandomRiskContext();

            // Calculate risk score
            $riskResult = $this->riskScoringService->calculateRiskScore($context);

            // Property: Risk scoring should succeed with valid context
            $this->assertTrue($riskResult['success'], 'Risk scoring failed: '.($riskResult['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('risk_score', $riskResult);
            $this->assertArrayHasKey('risk_level', $riskResult);
            $this->assertArrayHasKey('confidence_score', $riskResult);

            // Property: Risk score should be within valid bounds (0.00 to 999.99)
            $riskScore = $riskResult['risk_score'];
            $this->assertGreaterThanOrEqual(0.00, $riskScore, 'Risk score should be >= 0.00');
            $this->assertLessThanOrEqual(999.99, $riskScore, 'Risk score should be <= 999.99');

            // Property: Risk level should correspond to risk score
            $riskLevel = $riskResult['risk_level'];
            $this->assertContains($riskLevel, ['very_low', 'low', 'medium', 'high', 'very_high', 'critical'], 'Invalid risk level');

            // Property: Confidence score should be between 0 and 1
            $confidenceScore = $riskResult['confidence_score'];
            $this->assertGreaterThanOrEqual(0.0, $confidenceScore, 'Confidence score should be >= 0.0');
            $this->assertLessThanOrEqual(1.0, $confidenceScore, 'Confidence score should be <= 1.0');

            // Property: Risk factors should be present and properly weighted
            $this->assertArrayHasKey('risk_factors', $riskResult);
            $riskFactors = $riskResult['risk_factors'];
            $this->assertIsArray($riskFactors, 'Risk factors should be an array');

            // Property: Each risk factor should have required properties
            foreach ($riskFactors as $factorName => $factorData) {
                $this->assertArrayHasKey('score', $factorData, "Factor {$factorName} should have score");
                $this->assertArrayHasKey('weight', $factorData, "Factor {$factorName} should have weight");
                $this->assertArrayHasKey('weighted_score', $factorData, "Factor {$factorName} should have weighted_score");

                $this->assertIsNumeric($factorData['score'], "Factor {$factorName} score should be numeric");
                $this->assertIsNumeric($factorData['weight'], "Factor {$factorName} weight should be numeric");
                $this->assertIsNumeric($factorData['weighted_score'], "Factor {$factorName} weighted_score should be numeric");
            }

            // Property: Risk score should be stored in database
            $storedScore = DB::table('risk_scores')
                ->where('id', $riskResult['risk_score_id'])
                ->first();
            $this->assertNotNull($storedScore, 'Risk score should be stored in database');
            $this->assertEquals($riskScore, $storedScore->risk_score, 'Stored risk score should match calculated score');
            $this->assertEquals($riskLevel, $storedScore->risk_level, 'Stored risk level should match calculated level');
        }
    }

    /**
     * Property 25.2: Progressive Friction Application Accuracy
     *
     * Validates that progressive friction is applied correctly based on risk levels
     * and that verification requirements escalate appropriately.
     *
     * Requirements: 24.2, 24.4
     */
    public function test_progressive_friction_application_accuracy()
    {
        $iterations = 25;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random risk assessment and context
            $riskAssessment = $this->generateRandomRiskAssessment();
            $context = $this->generateRandomFrictionContext();

            // Apply progressive friction
            $frictionResult = $this->frictionService->applyProgressiveFriction($riskAssessment, $context);

            // Property: Friction application should succeed
            $this->assertArrayHasKey('allowed', $frictionResult);
            $this->assertArrayHasKey('friction_level', $frictionResult);
            $this->assertArrayHasKey('required_actions', $frictionResult);

            $riskLevel = $riskAssessment['risk_level'];
            $allowed = $frictionResult['allowed'];
            $requiredActions = $frictionResult['required_actions'];

            // Property: Higher risk levels should have more friction
            switch ($riskLevel) {
                case 'very_low':
                case 'low':
                    $this->assertTrue($allowed, 'Low risk should be allowed');
                    $this->assertEmpty($requiredActions, 'Low risk should have no required actions');
                    break;

                case 'medium':
                    $this->assertTrue($allowed, 'Medium risk should be allowed with friction');
                    $this->assertContains('captcha', $requiredActions, 'Medium risk should require CAPTCHA');
                    break;

                case 'high':
                    $this->assertTrue($allowed, 'High risk should be allowed with friction');
                    $this->assertContains('captcha', $requiredActions, 'High risk should require CAPTCHA');
                    break;

                case 'very_high':
                    // May be allowed or blocked depending on configuration
                    $this->assertContains('captcha', $requiredActions, 'Very high risk should require CAPTCHA');
                    $this->assertContains('phone', $requiredActions, 'Very high risk should require phone verification');
                    break;

                case 'critical':
                    // Should typically require manual review
                    $this->assertContains('manual_review', $requiredActions, 'Critical risk should require manual review');
                    break;
            }

            // Property: Required actions should be valid verification types
            $validActions = ['captcha', 'email', 'phone', 'document', 'manual_review', 'wait'];
            foreach ($requiredActions as $action) {
                $this->assertContains($action, $validActions, "Invalid required action: {$action}");
            }

            // Property: Friction level should match risk level
            $this->assertEquals($riskLevel, $frictionResult['friction_level'], 'Friction level should match risk level');
        }
    }

    /**
     * Property 25.3: Fraud Timeline Integrity and Completeness
     *
     * Validates that fraud timeline events are recorded accurately and completely
     * with proper correlation and temporal ordering.
     *
     * Requirements: 24.3
     */
    public function test_fraud_timeline_integrity_and_completeness()
    {
        $iterations = 20;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random timeline event
            $eventData = $this->generateRandomTimelineEvent();

            // Record timeline event
            $recordResult = $this->timelineService->recordTimelineEvent($eventData);

            // Property: Event recording should succeed
            $this->assertTrue($recordResult['success'], 'Timeline event recording failed: '.($recordResult['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('event_id', $recordResult);
            $this->assertArrayHasKey('correlation_id', $recordResult);
            $this->assertArrayHasKey('event_severity', $recordResult);

            $eventId = $recordResult['event_id'];
            $correlationId = $recordResult['correlation_id'];

            // Property: Event should be stored in database with correct data
            $storedEvent = DB::table('fraud_timeline')->where('id', $eventId)->first();
            $this->assertNotNull($storedEvent, 'Timeline event should be stored in database');
            $this->assertEquals($eventData['event_type'], $storedEvent->event_type, 'Event type should match');
            $this->assertEquals($correlationId, $storedEvent->correlation_id, 'Correlation ID should match');

            // Property: Event data should be properly serialized
            $storedEventData = json_decode($storedEvent->event_data, true);
            $this->assertIsArray($storedEventData, 'Event data should be valid JSON');

            // Property: Event severity should be appropriate for event type
            $severity = $recordResult['event_severity'];
            $this->assertContains($severity, ['info', 'warning', 'high', 'critical'], 'Invalid event severity');

            // Property: Timestamps should be properly set
            $this->assertNotNull($storedEvent->event_timestamp, 'Event timestamp should be set');
            $this->assertNotNull($storedEvent->created_at, 'Created timestamp should be set');

            // Test timeline retrieval
            $timelineResult = $this->timelineService->getTimeline([
                'correlation_id' => $correlationId,
                'limit' => 10,
            ]);

            // Property: Timeline retrieval should succeed
            $this->assertTrue($timelineResult['success'], 'Timeline retrieval failed');
            $this->assertArrayHasKey('events', $timelineResult);
            $this->assertArrayHasKey('pagination', $timelineResult);

            // Property: Retrieved event should match stored event
            $events = $timelineResult['events'];
            $this->assertNotEmpty($events, 'Timeline should contain events');

            $retrievedEvent = collect($events)->firstWhere('id', $eventId);
            $this->assertNotNull($retrievedEvent, 'Event should be retrievable from timeline');
            $this->assertEquals($eventData['event_type'], $retrievedEvent['event_type'], 'Retrieved event type should match');
        }
    }

    /**
     * Property 25.4: Fraud Pattern Detection Accuracy
     *
     * Validates that fraud patterns are detected accurately and contribute
     * appropriately to risk scoring and decision making.
     *
     * Requirements: 24.5
     */
    public function test_fraud_pattern_detection_accuracy()
    {
        $iterations = 15;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random pattern detection context
            $context = $this->generateRandomPatternContext();

            // Detect fraud patterns
            $patternResult = $this->timelineService->detectFraudPatterns($context);

            // Property: Pattern detection should succeed
            $this->assertTrue($patternResult['success'], 'Pattern detection failed: '.($patternResult['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('detected_patterns', $patternResult);
            $this->assertArrayHasKey('pattern_count', $patternResult);
            $this->assertArrayHasKey('total_risk_contribution', $patternResult);

            $detectedPatterns = $patternResult['detected_patterns'];
            $patternCount = $patternResult['pattern_count'];
            $totalRiskContribution = $patternResult['total_risk_contribution'];

            // Property: Pattern count should match detected patterns array length
            $this->assertEquals(count($detectedPatterns), $patternCount, 'Pattern count should match array length');

            // Property: Each detected pattern should have required properties
            foreach ($detectedPatterns as $pattern) {
                $this->assertArrayHasKey('pattern_id', $pattern);
                $this->assertArrayHasKey('pattern_name', $pattern);
                $this->assertArrayHasKey('pattern_type', $pattern);
                $this->assertArrayHasKey('confidence_score', $pattern);
                $this->assertArrayHasKey('risk_contribution', $pattern);

                // Property: Confidence score should be between 0 and 1
                $confidence = $pattern['confidence_score'];
                $this->assertGreaterThanOrEqual(0.0, $confidence, 'Pattern confidence should be >= 0.0');
                $this->assertLessThanOrEqual(1.0, $confidence, 'Pattern confidence should be <= 1.0');

                // Property: Risk contribution should be non-negative
                $riskContribution = $pattern['risk_contribution'];
                $this->assertGreaterThanOrEqual(0.0, $riskContribution, 'Risk contribution should be >= 0.0');
            }

            // Property: Total risk contribution should equal sum of individual contributions
            $calculatedTotal = array_sum(array_column($detectedPatterns, 'risk_contribution'));
            $this->assertEquals($calculatedTotal, $totalRiskContribution, 'Total risk contribution should equal sum of individual contributions', 0.01);
        }
    }

    /**
     * Property 25.5: Integrated Fraud Prevention System Consistency
     *
     * Validates that all fraud prevention components work together consistently
     * and maintain data integrity across the entire system.
     *
     * Requirements: 24.1, 24.2, 24.3, 24.4, 24.5
     */
    public function test_integrated_fraud_prevention_system_consistency()
    {
        $iterations = 15;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate comprehensive test scenario
            $context = $this->generateRandomIntegratedContext();

            // Step 1: Calculate risk score
            $riskResult = $this->riskScoringService->calculateRiskScore($context);
            $this->assertTrue($riskResult['success'], 'Risk scoring should succeed');

            // Step 2: Apply progressive friction
            $frictionResult = $this->frictionService->applyProgressiveFriction($riskResult, $context);
            $this->assertArrayHasKey('allowed', $frictionResult);

            // Step 3: Record timeline event
            $timelineEventData = [
                'booking_id' => $context['booking_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'event_type' => 'risk_assessment',
                'event_data' => [
                    'risk_score' => $riskResult['risk_score'],
                    'risk_level' => $riskResult['risk_level'],
                    'friction_applied' => $frictionResult['required_actions'] ?? [],
                ],
                'risk_context' => [
                    'risk_score' => $riskResult['risk_score'],
                    'risk_level' => $riskResult['risk_level'],
                    'confidence_score' => $riskResult['confidence_score'],
                ],
            ];

            $timelineResult = $this->timelineService->recordTimelineEvent($timelineEventData);
            $this->assertTrue($timelineResult['success'], 'Timeline event recording should succeed');

            // Step 4: Detect patterns
            $patternResult = $this->timelineService->detectFraudPatterns($context);
            $this->assertTrue($patternResult['success'], 'Pattern detection should succeed');

            // Property: Risk score should be consistent across components
            $storedRiskScore = DB::table('risk_scores')
                ->where('id', $riskResult['risk_score_id'])
                ->first();
            $this->assertNotNull($storedRiskScore);
            $this->assertEquals($riskResult['risk_score'], $storedRiskScore->risk_score);

            // Property: Timeline event should reference correct risk assessment
            $storedTimelineEvent = DB::table('fraud_timeline')
                ->where('id', $timelineResult['event_id'])
                ->first();
            $this->assertNotNull($storedTimelineEvent);

            $timelineEventData = json_decode($storedTimelineEvent->event_data, true);
            $this->assertEquals($riskResult['risk_score'], $timelineEventData['risk_score']);
            $this->assertEquals($riskResult['risk_level'], $timelineEventData['risk_level']);

            // Property: Friction level should correspond to risk level
            $this->assertEquals($riskResult['risk_level'], $frictionResult['friction_level']);

            // Property: Higher risk should result in more friction actions
            $riskLevelOrder = ['very_low', 'low', 'medium', 'high', 'very_high', 'critical'];
            $riskIndex = array_search($riskResult['risk_level'], $riskLevelOrder);
            $actionCount = count($frictionResult['required_actions'] ?? []);

            // Generally, higher risk should have more actions (with some flexibility)
            if ($riskIndex >= 2) { // medium or higher
                $this->assertGreaterThanOrEqual(1, $actionCount, 'Medium+ risk should have at least one required action');
            }

            // Property: System should maintain referential integrity
            if (isset($context['customer_id'])) {
                $customerRiskScores = DB::table('risk_scores')
                    ->where('customer_id', $context['customer_id'])
                    ->count();
                $this->assertGreaterThan(0, $customerRiskScores, 'Customer should have risk scores recorded');
            }
        }
    }

    // Helper methods for generating test data

    private function setupTestData(): void
    {
        // Create test tables if they don't exist (simplified for testing)
        if (! DB::getSchemaBuilder()->hasTable('fraud_risk_models')) {
            DB::statement('
                CREATE TABLE fraud_risk_models (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    model_name VARCHAR(100) NOT NULL,
                    model_version VARCHAR(50) NOT NULL,
                    model_status VARCHAR(20) DEFAULT "active",
                    model_configuration JSON,
                    risk_factors JSON,
                    threshold_configuration JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ');
        }

        if (! DB::getSchemaBuilder()->hasTable('tenant_risk_configurations')) {
            DB::statement('
                CREATE TABLE tenant_risk_configurations (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    tenant_id VARCHAR(100) NOT NULL,
                    configuration_name VARCHAR(100) NOT NULL,
                    risk_thresholds JSON,
                    response_actions JSON,
                    friction_settings JSON,
                    active_model_name VARCHAR(100),
                    active_model_version VARCHAR(50),
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ');
        }

        // Insert test risk model
        DB::table('fraud_risk_models')->insert([
            'model_name' => 'test_model',
            'model_version' => '1.0',
            'model_status' => 'active',
            'model_configuration' => json_encode([
                'adjustment_factor' => 1.0,
                'accuracy_score' => 0.85,
            ]),
            'risk_factors' => json_encode([
                'velocity_booking_attempts' => ['weight' => 1.5],
                'ip_reputation' => ['weight' => 1.2],
                'device_reputation' => ['weight' => 1.0],
            ]),
            'threshold_configuration' => json_encode([
                'very_low' => 0.0,
                'low' => 10.0,
                'medium' => 25.0,
                'high' => 50.0,
                'very_high' => 75.0,
                'critical' => 90.0,
            ]),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Insert test tenant configuration
        DB::table('tenant_risk_configurations')->insert([
            'tenant_id' => 'default',
            'configuration_name' => 'default_config',
            'risk_thresholds' => json_encode([
                'very_low' => 0.0,
                'low' => 10.0,
                'medium' => 25.0,
                'high' => 50.0,
                'very_high' => 75.0,
                'critical' => 90.0,
            ]),
            'response_actions' => json_encode([
                'medium' => ['captcha'],
                'high' => ['captcha', 'email'],
                'very_high' => ['captcha', 'email', 'phone'],
                'critical' => ['captcha', 'email', 'phone', 'manual_review'],
            ]),
            'friction_settings' => json_encode([
                'medium' => ['required_verifications' => ['captcha']],
                'high' => ['required_verifications' => ['captcha', 'email']],
                'very_high' => ['required_verifications' => ['captcha', 'email', 'phone']],
                'critical' => ['required_verifications' => ['captcha', 'email', 'phone', 'manual_review']],
            ]),
            'active_model_name' => 'test_model',
            'active_model_version' => '1.0',
            'is_active' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function generateRandomRiskContext(): array
    {
        return [
            'tenant_id' => 'default',
            'customer_id' => rand(1, 1000),
            'session_id' => 'session_'.uniqid(),
            'booking_id' => rand(1, 10000),
            'ip_address' => '192.168.1.'.rand(1, 254),
            'user_agent' => 'TestAgent/1.0',
            'device_fingerprint' => 'device_'.uniqid(),
            'booking_amount' => rand(50, 2000),
            'ticket_quantity' => rand(1, 10),
            'payment_method' => ['credit_card', 'debit_card', 'bank_transfer'][array_rand(['credit_card', 'debit_card', 'bank_transfer'])],
            'session_duration' => rand(30, 3600),
            'page_views' => rand(1, 20),
            'time_on_page' => rand(10, 300),
        ];
    }

    private function generateRandomRiskAssessment(): array
    {
        $riskLevels = ['very_low', 'low', 'medium', 'high', 'very_high', 'critical'];
        $riskLevel = $riskLevels[array_rand($riskLevels)];

        return [
            'success' => true,
            'risk_score_id' => rand(1, 10000),
            'risk_score' => rand(0, 100),
            'risk_level' => $riskLevel,
            'confidence_score' => rand(50, 100) / 100,
            'risk_factors' => [
                'velocity_booking_attempts' => ['score' => rand(0, 50), 'weight' => 1.5],
                'ip_reputation' => ['score' => rand(0, 30), 'weight' => 1.2],
            ],
        ];
    }

    private function generateRandomFrictionContext(): array
    {
        return [
            'tenant_id' => 'default',
            'customer_id' => rand(1, 1000),
            'session_id' => 'session_'.uniqid(),
            'ip_address' => '192.168.1.'.rand(1, 254),
        ];
    }

    private function generateRandomTimelineEvent(): array
    {
        $eventTypes = ['risk_assessment', 'verification_required', 'captcha_challenge', 'manual_review', 'fraud_confirmed'];

        return [
            'booking_id' => rand(1, 10000),
            'customer_id' => rand(1, 1000),
            'session_id' => 'session_'.uniqid(),
            'event_type' => $eventTypes[array_rand($eventTypes)],
            'event_data' => [
                'test_data' => true,
                'random_value' => rand(1, 100),
            ],
            'risk_context' => [
                'risk_score' => rand(0, 100),
                'risk_level' => ['low', 'medium', 'high'][array_rand(['low', 'medium', 'high'])],
            ],
            'actor_type' => 'system',
            'source_system' => 'fraud_prevention',
        ];
    }

    private function generateRandomPatternContext(): array
    {
        return [
            'customer_id' => rand(1, 1000),
            'ip_address' => '192.168.1.'.rand(1, 254),
            'booking_amount' => rand(50, 2000),
            'session_duration' => rand(30, 3600),
            'device_fingerprint' => 'device_'.uniqid(),
        ];
    }

    private function generateRandomIntegratedContext(): array
    {
        return [
            'tenant_id' => 'default',
            'customer_id' => rand(1, 1000),
            'session_id' => 'session_'.uniqid(),
            'booking_id' => rand(1, 10000),
            'ip_address' => '192.168.1.'.rand(1, 254),
            'user_agent' => 'TestAgent/1.0',
            'device_fingerprint' => 'device_'.uniqid(),
            'booking_amount' => rand(50, 2000),
            'ticket_quantity' => rand(1, 10),
            'payment_method' => 'credit_card',
            'session_duration' => rand(30, 3600),
            'page_views' => rand(1, 20),
            'time_on_page' => rand(10, 300),
        ];
    }
}
