<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Modules\EventManagement\Services\EligibilityAuditService;
use Modules\EventManagement\Services\LoyaltyLedgerService;
use Modules\EventManagement\Services\EnhancedFanTierService;
use Carbon\Carbon;

/**
 * Property Test 24: Loyalty Decision Auditability and Points Integrity
 * 
 * Validates Requirements: 23.1, 23.2, 23.3, 23.4, 23.5
 * 
 * This property-based test validates that the loyalty auditability system
 * maintains decision logging integrity, points transaction consistency,
 * and comprehensive audit trails across all scenarios.
 */
class LoyaltyAuditabilityTest extends TestCase
{
    use RefreshDatabase;

    protected EligibilityAuditService $eligibilityAuditService;
    protected LoyaltyLedgerService $loyaltyLedgerService;
    protected EnhancedFanTierService $fanTierService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->eligibilityAuditService = app(EligibilityAuditService::class);
        $this->loyaltyLedgerService = app(LoyaltyLedgerService::class);
        $this->fanTierService = app(EnhancedFanTierService::class);
        
        // Clear cache for clean test state
        Cache::flush();
        
        $this->setupTestData();
    }

    /**
     * Property 24.1: Eligibility Decision Logging Integrity
     * 
     * Validates that all eligibility decisions are logged with complete
     * audit trails and proper rule version tracking.
     * 
     * Requirements: 23.1, 23.2
     */
    public function test_eligibility_decision_logging_integrity()
    {
        $iterations = 25;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Generate random decision data
            $decisionData = $this->generateRandomDecisionData();
            
            // Log eligibility decision
            $result = $this->eligibilityAuditService->logEligibilityDecision($decisionData);
            
            // Property: Decision logging should succeed with valid data
            $this->assertTrue($result['success'], "Decision logging failed: " . ($result['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('decision_id', $result);
            
            // Property: Decision should be retrievable from database
            $storedDecision = DB::table('eligibility_decisions')->where('id', $result['decision_id'])->first();
            $this->assertNotNull($storedDecision, "Decision should be stored in database");
            $this->assertEquals($decisionData['customer_id'], $storedDecision->customer_id);
            $this->assertEquals($decisionData['decision_type'], $storedDecision->decision_type);
            $this->assertEquals($decisionData['decision_result'], $storedDecision->decision_result);
            
            // Property: Decision data should be properly serialized
            $storedInputData = json_decode($storedDecision->decision_data, true);
            $this->assertIsArray($storedInputData, "Decision data should be valid JSON");
            
            $storedEvaluationLog = json_decode($storedDecision->rule_evaluation_log, true);
            $this->assertIsArray($storedEvaluationLog, "Evaluation log should be valid JSON");
            
            // Property: Rule version should be tracked
            $this->assertNotEmpty($storedDecision->rule_version_applied, "Rule version should be tracked");
            
            // Property: Timestamps should be properly set
            $this->assertNotNull($storedDecision->decided_at, "Decision timestamp should be set");
            $this->assertNotNull($storedDecision->created_at, "Created timestamp should be set");
        }
    }

    /**
     * Property 24.2: Points Transaction Idempotency
     * 
     * Validates that points transactions are idempotent
     * and maintain balance consistency across all operations.
     * 
     * Requirements: 23.3, 23.4
     */
    public function test_points_transaction_idempotency()
    {
        $iterations = 25;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Create test customer
            $customerId = $this->createTestCustomer();
            
            // Generate random transaction data
            $transactionData = $this->generateRandomTransactionData($customerId);
            
            // Process transaction first time
            $result1 = $this->loyaltyLedgerService->processTransaction($transactionData);
            
            // Property: Transaction should succeed with valid data
            $this->assertTrue($result1['success'], "Transaction failed: " . ($result1['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('transaction_id', $result1);
            $this->assertArrayHasKey('points_balance', $result1);
            $this->assertFalse($result1['is_duplicate'] ?? false, "First transaction should not be duplicate");
            
            // Process same transaction again (same idempotency key)
            $result2 = $this->loyaltyLedgerService->processTransaction($transactionData);
            
            // Property: Duplicate transaction should be detected
            $this->assertTrue($result2['success'], "Duplicate transaction handling failed");
            $this->assertTrue($result2['is_duplicate'], "Duplicate transaction should be flagged");
            $this->assertEquals($result1['transaction_id'], $result2['transaction_id'], "Same transaction ID should be returned");
            $this->assertEquals($result1['points_balance'], $result2['points_balance'], "Same balance should be returned");
            
            // Property: Only one transaction should exist in database
            $transactionCount = DB::table('loyalty_ledger')
                ->where('idempotency_key', $transactionData['idempotency_key'])
                ->count();
            $this->assertEquals(1, $transactionCount, "Only one transaction should exist for idempotency key");
            
            // Property: Balance should be consistent
            $currentBalance = $this->loyaltyLedgerService->getCurrentBalance($customerId);
            $this->assertEquals($result1['points_balance'], $currentBalance, "Current balance should match transaction result");
        }
    }

    /**
     * Property 24.3: Integrated Loyalty System Consistency
     * 
     * Validates that the enhanced fan tier service maintains consistency
     * between eligibility decisions and points transactions.
     * 
     * Requirements: 23.1, 23.4, 23.5
     */
    public function test_integrated_loyalty_system_consistency()
    {
        $iterations = 20;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Create test customer and event
            $customerId = $this->createTestCustomer();
            $eventId = $this->createTestEvent();
            $bookingId = $this->createTestBooking($customerId, $eventId);
            
            // Generate random context
            $context = $this->generateRandomContext();
            
            // Test tier access check
            $tierAccessResult = $this->fanTierService->checkTierAccess($customerId, $eventId, $context);
            
            // Property: Tier access check should succeed
            $this->assertTrue($tierAccessResult['success'], "Tier access check failed: " . ($tierAccessResult['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('decision_id', $tierAccessResult);
            $this->assertArrayHasKey('eligible', $tierAccessResult);
            
            // Property: Decision should be logged in eligibility_decisions
            if ($tierAccessResult['decision_id']) {
                $loggedDecision = DB::table('eligibility_decisions')
                    ->where('id', $tierAccessResult['decision_id'])
                    ->first();
                $this->assertNotNull($loggedDecision, "Decision should be logged");
                $this->assertEquals($customerId, $loggedDecision->customer_id);
                $this->assertEquals($eventId, $loggedDecision->event_id);
                $this->assertEquals('tier_access', $loggedDecision->decision_type);
            }
            
            // Test points earning
            $earningData = $this->generateRandomEarningData();
            $earningResult = $this->fanTierService->processPointsEarning($customerId, $bookingId, $earningData);
            
            // Property: Points earning should succeed
            $this->assertTrue($earningResult['success'], "Points earning failed: " . ($earningResult['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('new_balance', $earningResult);
            $this->assertArrayHasKey('points_earned', $earningResult);
            
            // Property: Transaction should be recorded in ledger
            $ledgerTransaction = DB::table('loyalty_ledger')
                ->where('reference_type', 'booking')
                ->where('reference_id', $bookingId)
                ->where('customer_id', $customerId)
                ->first();
            $this->assertNotNull($ledgerTransaction, "Transaction should be recorded in ledger");
            $this->assertEquals($earningResult['points_earned'], $ledgerTransaction->points_delta);
            
            // Property: Balance should be updated correctly
            $initialBalance = $this->loyaltyLedgerService->getCurrentBalance($customerId);
            $expectedNewBalance = $initialBalance + $earningResult['points_earned'];
            $this->assertEquals($expectedNewBalance, $earningResult['new_balance'], "Balance should be updated correctly");
        }
    }

    // Helper methods for generating test data

    private function setupTestData(): void
    {
        // Create customers table if it doesn't exist
        if (!DB::getSchemaBuilder()->hasTable('customers')) {
            DB::statement('
                CREATE TABLE customers (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ');
        }

        // Create customer_tiers table if it doesn't exist
        if (!DB::getSchemaBuilder()->hasTable('customer_tiers')) {
            DB::statement('
                CREATE TABLE customer_tiers (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    customer_id BIGINT NOT NULL,
                    tier_name VARCHAR(50) NOT NULL,
                    tier_level INT NOT NULL,
                    points_balance INT DEFAULT 0,
                    tier_benefits JSON,
                    tier_since TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (customer_id) REFERENCES customers(id)
                )
            ');
        }

        // Create events table if it doesn't exist
        if (!DB::getSchemaBuilder()->hasTable('events')) {
            DB::statement('
                CREATE TABLE events (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    event_date DATETIME,
                    venue VARCHAR(255),
                    capacity INT,
                    status VARCHAR(50) DEFAULT "active",
                    tier_restricted BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ');
        }

        // Create bookings table if it doesn't exist
        if (!DB::getSchemaBuilder()->hasTable('bookings')) {
            DB::statement('
                CREATE TABLE bookings (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    customer_id BIGINT NOT NULL,
                    event_id BIGINT NOT NULL,
                    total_amount DECIMAL(10,2) DEFAULT 0,
                    status VARCHAR(50) DEFAULT "pending",
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (customer_id) REFERENCES customers(id),
                    FOREIGN KEY (event_id) REFERENCES events(id)
                )
            ');
        }
    }

    private function generateRandomDecisionData(): array
    {
        $decisionTypes = ['tier_access', 'early_access', 'discount_eligibility', 'points_redemption'];
        $decisionResults = ['allowed', 'blocked', 'conditional'];
        
        return [
            'customer_id' => rand(1, 1000),
            'event_id' => rand(1, 100),
            'decision_type' => $decisionTypes[array_rand($decisionTypes)],
            'decision_result' => $decisionResults[array_rand($decisionResults)],
            'decision_reason' => 'Test decision reason ' . uniqid(),
            'rule_name' => 'test_rule_' . uniqid(),
            'input_data' => [
                'test_input' => 'value_' . uniqid(),
                'random_value' => rand(1, 100)
            ],
            'evaluation_log' => [
                'step_1' => 'Evaluated condition A',
                'step_2' => 'Evaluated condition B',
                'final_decision' => 'Decision made'
            ],
            'context' => 'test',
            'session_id' => 'session_' . uniqid(),
            'ip_address' => '192.168.1.' . rand(1, 254),
            'user_agent' => 'TestAgent/1.0',
            'confidence_score' => rand(50, 100) / 100
        ];
    }

    private function generateRandomTransactionData(int $customerId): array
    {
        $transactionTypes = ['earn', 'redeem', 'adjustment', 'bonus'];
        $referenceTypes = ['booking', 'event', 'manual', 'system'];
        
        $transactionType = $transactionTypes[array_rand($transactionTypes)];
        $pointsDelta = $transactionType === 'redeem' ? -rand(1, 100) : rand(1, 1000);
        
        return [
            'customer_id' => $customerId,
            'transaction_type' => $transactionType,
            'points_delta' => $pointsDelta,
            'reference_type' => $referenceTypes[array_rand($referenceTypes)],
            'reference_id' => rand(1, 1000),
            'idempotency_key' => 'test_' . uniqid(),
            'transaction_reason' => 'Test transaction ' . uniqid(),
            'metadata' => [
                'test_data' => true,
                'random_value' => rand(1, 1000)
            ]
        ];
    }

    private function generateRandomContext(): array
    {
        return [
            'source' => 'web',
            'session_id' => 'session_' . uniqid(),
            'ip_address' => '192.168.1.' . rand(1, 254),
            'user_agent' => 'TestAgent/1.0',
            'confidence_score' => rand(50, 100) / 100
        ];
    }

    private function generateRandomEarningData(): array
    {
        return [
            'reason' => 'Test points earning',
            'base_points' => rand(10, 100),
            'bonus_points' => rand(0, 50),
            'purchase_amount' => rand(50, 1000),
            'reference_type' => 'test_redemption',
            'metadata' => [
                'test_action' => 'points_redemption',
                'granted_value' => rand(1, 100)
            ]
        ];
    }

    private function createTestCustomer(): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Test Customer ' . uniqid(),
            'email' => 'test_' . uniqid() . '@example.com',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now()
        ]);
        
        // Create customer tier
        DB::table('customer_tiers')->insert([
            'customer_id' => $customerId,
            'tier_name' => 'gold',
            'tier_level' => rand(1, 5),
            'points_balance' => rand(0, 1000),
            'tier_benefits' => json_encode(['early_access' => true]),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now()
        ]);
        
        return $customerId;
    }

    private function createTestEvent(): int
    {
        return DB::table('events')->insertGetId([
            'name' => 'Test Event ' . uniqid(),
            'description' => 'Test event description',
            'event_date' => Carbon::now()->addDays(rand(1, 30)),
            'venue' => 'Test Venue',
            'capacity' => rand(100, 5000),
            'status' => 'active',
            'tier_restricted' => rand(0, 1) == 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now()
        ]);
    }

    private function createTestBooking(int $customerId, int $eventId): int
    {
        return DB::table('bookings')->insertGetId([
            'customer_id' => $customerId,
            'event_id' => $eventId,
            'total_amount' => rand(50, 500),
            'status' => 'confirmed',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now()
        ]);
    }
}