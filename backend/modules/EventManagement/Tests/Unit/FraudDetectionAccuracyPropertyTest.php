<?php

namespace Tests\Unit;

use Tests\TestCase;
use Modules\EventManagement\Services\FraudDetectionService;
use Modules\EventManagement\Services\AntiAbuseService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Models\FraudDetectionRule;
use Modules\EventManagement\Models\IpRestriction;
use Modules\EventManagement\Models\BookingVelocityLog;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Property-Based Test: Fraud Detection Accuracy
 * 
 * Feature: erp-event-management-phase2, Property 15: Fraud Detection Accuracy
 * 
 * This test validates that the fraud detection system accurately identifies
 * suspicious patterns while allowing legitimate bookings to proceed without interference.
 * 
 * Validates: Requirements 14.1, 14.2, 14.3, 14.4, 14.5
 */
class FraudDetectionAccuracyPropertyTest extends TestCase
{
    use RefreshDatabase;

    private FraudDetectionService $fraudDetectionService;
    private AntiAbuseService $antiAbuseService;
    private BookingService $bookingService;
    private string $tenantId = 'test-tenant';

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->fraudDetectionService = app(FraudDetectionService::class);
        $this->antiAbuseService = app(AntiAbuseService::class);
        $this->bookingService = app(BookingService::class);
        
        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Property 15: Fraud Detection Accuracy
     * 
     * For any booking attempt, the fraud detection system should accurately identify
     * suspicious patterns while allowing legitimate bookings to proceed without interference.
     * 
     * This property tests:
     * 1. Legitimate bookings are not falsely flagged as fraudulent
     * 2. Suspicious patterns are correctly identified and flagged
     * 3. Risk scores are proportional to actual risk factors
     * 4. IP restrictions are properly enforced
     * 5. Rate limiting works correctly without blocking legitimate users
     * 
     * @test
     */
    public function property_fraud_detection_accurately_identifies_suspicious_patterns_while_allowing_legitimate_bookings()
    {
        $iterations = 150; // Higher iterations for comprehensive fraud testing
        $legitimateBookingsBlocked = 0;
        $suspiciousBookingsAllowed = 0;
        $totalLegitimate = 0;
        $totalSuspicious = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate test scenarios
            $scenario = $this->generateBookingScenario();
            
            if ($scenario['is_legitimate']) {
                $totalLegitimate++;
                $result = $this->testLegitimateBooking($scenario);
                if ($result['blocked']) {
                    $legitimateBookingsBlocked++;
                }
            } else {
                $totalSuspicious++;
                $result = $this->testSuspiciousBooking($scenario);
                if (!$result['blocked'] && !$result['flagged']) {
                    $suspiciousBookingsAllowed++;
                }
            }
            
            // Validate risk score accuracy
            $this->validateRiskScoreAccuracy($scenario, $result);
            
            // Clean up for next iteration
            $this->cleanupTestData();
        }

        // Property assertions
        $falsePositiveRate = $totalLegitimate > 0 ? ($legitimateBookingsBlocked / $totalLegitimate) * 100 : 0;
        $falseNegativeRate = $totalSuspicious > 0 ? ($suspiciousBookingsAllowed / $totalSuspicious) * 100 : 0;

        // Fraud detection should have low false positive rate (< 5%)
        $this->assertLessThan(5, $falsePositiveRate, 
            "False positive rate too high: {$falsePositiveRate}% of legitimate bookings were blocked");

        // Fraud detection should have low false negative rate (< 15%)
        $this->assertLessThan(15, $falseNegativeRate, 
            "False negative rate too high: {$falseNegativeRate}% of suspicious bookings were allowed");

        // Log test results
        $this->addToAssertionCount(1);
        echo "\nFraud Detection Accuracy Results:\n";
        echo "- Total legitimate bookings tested: {$totalLegitimate}\n";
        echo "- Total suspicious bookings tested: {$totalSuspicious}\n";
        echo "- False positive rate: " . number_format($falsePositiveRate, 2) . "%\n";
        echo "- False negative rate: " . number_format($falseNegativeRate, 2) . "%\n";
    }

    /**
     * Generate a booking scenario with known legitimacy status
     */
    private function generateBookingScenario(): array
    {
        $isLegitimate = rand(0, 1) === 1;
        
        if ($isLegitimate) {
            return $this->generateLegitimateScenario();
        } else {
            return $this->generateSuspiciousScenario();
        }
    }

    /**
     * Generate a legitimate booking scenario
     */
    private function generateLegitimateScenario(): array
    {
        return [
            'is_legitimate' => true,
            'customer_id' => rand(1, 1000),
            'ip_address' => $this->generateRandomIp(),
            'user_agent' => $this->generateLegitimateUserAgent(),
            'event_id' => rand(1, 100),
            'tickets_requested' => rand(1, 4), // Normal ticket quantity
            'session_id' => 'session_' . uniqid(),
            'total_amount' => rand(50, 500),
            'velocity_pattern' => 'normal', // Normal booking frequency
            'risk_factors' => [],
        ];
    }

    /**
     * Generate a suspicious booking scenario
     */
    private function generateSuspiciousScenario(): array
    {
        $suspiciousPatterns = [
            'high_velocity', 'bot_user_agent', 'excessive_tickets', 
            'rapid_fire', 'multiple_events', 'blocked_ip'
        ];
        
        $pattern = $suspiciousPatterns[array_rand($suspiciousPatterns)];
        
        $scenario = [
            'is_legitimate' => false,
            'customer_id' => rand(1, 1000),
            'ip_address' => $this->generateRandomIp(),
            'user_agent' => $this->generateLegitimateUserAgent(),
            'event_id' => rand(1, 100),
            'tickets_requested' => rand(1, 4),
            'session_id' => 'session_' . uniqid(),
            'total_amount' => rand(50, 500),
            'velocity_pattern' => 'normal',
            'risk_factors' => [$pattern],
        ];

        // Apply suspicious characteristics based on pattern
        switch ($pattern) {
            case 'high_velocity':
                $scenario['velocity_pattern'] = 'high';
                break;
            case 'bot_user_agent':
                $scenario['user_agent'] = $this->generateBotUserAgent();
                break;
            case 'excessive_tickets':
                $scenario['tickets_requested'] = rand(10, 20);
                break;
            case 'rapid_fire':
                $scenario['velocity_pattern'] = 'rapid';
                break;
            case 'multiple_events':
                $scenario['velocity_pattern'] = 'multiple_events';
                break;
            case 'blocked_ip':
                $scenario['ip_address'] = $this->createBlockedIp();
                break;
        }

        return $scenario;
    }

    /**
     * Test a legitimate booking scenario
     */
    private function testLegitimateBooking(array $scenario): array
    {
        // Simulate normal booking velocity
        if ($scenario['velocity_pattern'] === 'normal') {
            $this->simulateNormalVelocity($scenario);
        }

        // Test fraud detection
        $bookingData = [
            'tenant_id' => $this->tenantId,
            'customer_id' => $scenario['customer_id'],
            'ip_address' => $scenario['ip_address'],
            'user_agent' => $scenario['user_agent'],
            'event_id' => $scenario['event_id'],
            'tickets_requested' => $scenario['tickets_requested'],
            'session_id' => $scenario['session_id'],
            'total_amount' => $scenario['total_amount'],
        ];

        $fraudAnalysis = $this->fraudDetectionService->analyzeBookingAttempt($bookingData);
        $ipCheck = $this->fraudDetectionService->checkIpRestriction($this->tenantId, $scenario['ip_address']);

        return [
            'blocked' => $ipCheck['restricted'] || $fraudAnalysis['recommendation'] === 'block',
            'flagged' => $fraudAnalysis['recommendation'] === 'flag',
            'risk_score' => $fraudAnalysis['risk_score'],
            'recommendation' => $fraudAnalysis['recommendation'],
        ];
    }

    /**
     * Test a suspicious booking scenario
     */
    private function testSuspiciousBooking(array $scenario): array
    {
        // Simulate suspicious patterns
        $this->simulateSuspiciousPattern($scenario);

        // Test fraud detection
        $bookingData = [
            'tenant_id' => $this->tenantId,
            'customer_id' => $scenario['customer_id'],
            'ip_address' => $scenario['ip_address'],
            'user_agent' => $scenario['user_agent'],
            'event_id' => $scenario['event_id'],
            'tickets_requested' => $scenario['tickets_requested'],
            'session_id' => $scenario['session_id'],
            'total_amount' => $scenario['total_amount'],
        ];

        $fraudAnalysis = $this->fraudDetectionService->analyzeBookingAttempt($bookingData);
        $ipCheck = $this->fraudDetectionService->checkIpRestriction($this->tenantId, $scenario['ip_address']);

        return [
            'blocked' => $ipCheck['restricted'] || $fraudAnalysis['recommendation'] === 'block',
            'flagged' => $fraudAnalysis['recommendation'] === 'flag',
            'risk_score' => $fraudAnalysis['risk_score'],
            'recommendation' => $fraudAnalysis['recommendation'],
        ];
    }

    /**
     * Validate that risk scores are proportional to actual risk factors
     */
    private function validateRiskScoreAccuracy(array $scenario, array $result): void
    {
        if ($scenario['is_legitimate']) {
            // Legitimate bookings should have low risk scores (< 30)
            $this->assertLessThan(30, $result['risk_score'], 
                "Legitimate booking has unexpectedly high risk score: {$result['risk_score']}");
        } else {
            // Suspicious bookings should have higher risk scores (> 20)
            $this->assertGreaterThan(20, $result['risk_score'], 
                "Suspicious booking has unexpectedly low risk score: {$result['risk_score']}");
        }
    }

    /**
     * Simulate normal booking velocity
     */
    private function simulateNormalVelocity(array $scenario): void
    {
        // Create 1-2 normal booking attempts in the last hour
        $attempts = rand(1, 2);
        for ($i = 0; $i < $attempts; $i++) {
            BookingVelocityLog::create([
                'tenant_id' => $this->tenantId,
                'customer_id' => $scenario['customer_id'],
                'ip_address' => $scenario['ip_address'],
                'event_id' => $scenario['event_id'],
                'tickets_requested' => rand(1, 3),
                'action_type' => 'booking_attempt',
                'created_at' => now()->subMinutes(rand(10, 60)),
            ]);
        }
    }

    /**
     * Simulate suspicious patterns
     */
    private function simulateSuspiciousPattern(array $scenario): void
    {
        switch ($scenario['velocity_pattern']) {
            case 'high':
                // Create many booking attempts
                for ($i = 0; $i < rand(8, 15); $i++) {
                    BookingVelocityLog::create([
                        'tenant_id' => $this->tenantId,
                        'customer_id' => $scenario['customer_id'],
                        'ip_address' => $scenario['ip_address'],
                        'event_id' => $scenario['event_id'],
                        'tickets_requested' => rand(1, 10),
                        'action_type' => 'booking_attempt',
                        'created_at' => now()->subMinutes(rand(1, 60)),
                    ]);
                }
                break;

            case 'rapid':
                // Create rapid-fire attempts
                for ($i = 0; $i < rand(5, 8); $i++) {
                    BookingVelocityLog::create([
                        'tenant_id' => $this->tenantId,
                        'customer_id' => $scenario['customer_id'],
                        'ip_address' => $scenario['ip_address'],
                        'event_id' => $scenario['event_id'],
                        'tickets_requested' => rand(1, 5),
                        'action_type' => 'booking_attempt',
                        'created_at' => now()->subMinutes(rand(1, 5)),
                    ]);
                }
                break;

            case 'multiple_events':
                // Create attempts for multiple events
                for ($i = 0; $i < rand(3, 6); $i++) {
                    BookingVelocityLog::create([
                        'tenant_id' => $this->tenantId,
                        'customer_id' => $scenario['customer_id'],
                        'ip_address' => $scenario['ip_address'],
                        'event_id' => rand(1, 100),
                        'tickets_requested' => rand(1, 5),
                        'action_type' => 'booking_attempt',
                        'created_at' => now()->subMinutes(rand(1, 30)),
                    ]);
                }
                break;
        }
    }

    /**
     * Generate a random IP address
     */
    private function generateRandomIp(): string
    {
        return rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255);
    }

    /**
     * Generate a legitimate user agent
     */
    private function generateLegitimateUserAgent(): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Android 11; Mobile; rv:89.0) Gecko/89.0 Firefox/89.0',
        ];
        
        return $userAgents[array_rand($userAgents)];
    }

    /**
     * Generate a bot user agent
     */
    private function generateBotUserAgent(): string
    {
        $botAgents = [
            'curl/7.68.0',
            'python-requests/2.25.1',
            'Mozilla/5.0 (compatible; bot/1.0)',
            'Scrapy/2.5.0',
            'HeadlessChrome/91.0.4472.124',
        ];
        
        return $botAgents[array_rand($botAgents)];
    }

    /**
     * Create a blocked IP and return it
     */
    private function createBlockedIp(): string
    {
        $ip = $this->generateRandomIp();
        
        IpRestriction::create([
            'tenant_id' => $this->tenantId,
            'ip_address' => $ip,
            'restriction_type' => IpRestriction::TYPE_BLOCKED,
            'reason' => 'Test blocked IP',
            'is_active' => true,
            'expires_at' => now()->addHours(24),
        ]);
        
        return $ip;
    }

    /**
     * Clean up test data between iterations
     */
    private function cleanupTestData(): void
    {
        BookingVelocityLog::where('tenant_id', $this->tenantId)->delete();
        IpRestriction::where('tenant_id', $this->tenantId)->delete();
        Cache::flush();
    }
}