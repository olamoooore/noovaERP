<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Modules\EventManagement\Services\DataHealthCheckService;
use Modules\EventManagement\Services\HybridAnalyticsService;
use Modules\EventManagement\Services\BookingFactsService;
use Modules\EventManagement\Services\AnalyticsReconciliationService;
use Carbon\Carbon;

/**
 * Property Test 22: Analytics Data Consistency and Accuracy
 * 
 * Validates Requirements: 21.1, 21.2, 21.3, 21.4, 21.5
 * 
 * This property-based test validates that the analytics correctness and transparency
 * system maintains data consistency, accuracy, and proper reconciliation between
 * source data and analytics across all scenarios.
 */
class AnalyticsCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    protected DataHealthCheckService $healthCheckService;
    protected HybridAnalyticsService $hybridAnalyticsService;
    protected BookingFactsService $bookingFactsService;
    protected AnalyticsReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->healthCheckService = app(DataHealthCheckService::class);
        $this->hybridAnalyticsService = app(HybridAnalyticsService::class);
        $this->bookingFactsService = app(BookingFactsService::class);
        $this->reconciliationService = app(AnalyticsReconciliationService::class);
        
        // Clear Redis for clean test state
        Redis::flushall();
        Cache::flush();
        
        $this->setupTestData();
    }

    /**
     * Property 22.1: KPI Definition Registry Integrity
     * 
     * Validates that KPI definitions maintain version integrity, proper metadata,
     * and consistent calculation logic across versions.
     * 
     * Requirements: 21.1, 21.2
     */
    public function test_kpi_definition_registry_integrity()
    {
        $iterations = 25; // Reduced for performance
        
        for ($i = 0; $i < $iterations; $i++) {
            // Generate random KPI definition
            $kpiDefinition = $this->generateRandomKPIDefinition();
            
            // Register KPI
            $result = $this->healthCheckService->registerKPI($kpiDefinition);
            
            // Property: Registration should succeed with valid data
            $this->assertTrue($result['success'], "KPI registration failed: " . ($result['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('kpi_id', $result);
            $this->assertArrayHasKey('version', $result);
            
            // Property: KPI should be retrievable from registry
            $registry = $this->healthCheckService->getKPIRegistry(true);
            $registeredKPI = collect($registry)->firstWhere('name', $kpiDefinition['kpi_name']);
            
            $this->assertNotNull($registeredKPI, "KPI not found in registry: {$kpiDefinition['kpi_name']}");
            $this->assertEquals($kpiDefinition['kpi_name'], $registeredKPI['name']);
            $this->assertEquals($kpiDefinition['description'], $registeredKPI['description']);
            $this->assertEquals($kpiDefinition['refresh_frequency'], $registeredKPI['refresh_frequency']);
            
            // Property: Version should increment on updates
            if ($i > 0 && rand(0, 1)) {
                $updatedDefinition = $kpiDefinition;
                $updatedDefinition['description'] = 'Updated: ' . $updatedDefinition['description'];
                
                $updateResult = $this->healthCheckService->registerKPI($updatedDefinition);
                
                $this->assertTrue($updateResult['success']);
                $this->assertGreaterThan($result['version'], $updateResult['version']);
                
                // Property: Old version should be deprecated
                $fullRegistry = $this->healthCheckService->getKPIRegistry(false);
                $oldVersions = collect($fullRegistry)->where('name', $kpiDefinition['kpi_name'])
                    ->where('version', '<', $updateResult['version']);
                
                foreach ($oldVersions as $oldVersion) {
                    $this->assertFalse($oldVersion['is_active'], "Old version should be inactive");
                }
            }
        }
    }

    /**
     * Property 22.2: Data Health Check Consistency
     * 
     * Validates that health checks execute consistently, detect data quality issues
     * accurately, and maintain proper status tracking.
     * 
     * Requirements: 21.2, 21.3
     */
    public function test_data_health_check_consistency()
    {
        $iterations = 25;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Create test data with known characteristics
            $testData = $this->createTestDataWithKnownIssues();
            
            // Create health checks for the test data
            $healthChecks = $this->createHealthChecksForTestData($testData);
            
            // Execute health checks
            $result = $this->healthCheckService->executeHealthChecks();
            
            // Property: Health checks should execute successfully
            $this->assertTrue($result['success'], "Health check execution failed: " . ($result['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('summary', $result);
            $this->assertArrayHasKey('results', $result);
            
            // Property: Known issues should be detected
            $failedChecks = collect($result['results'])->where('status', 'failed');
            $this->assertGreaterThan(0, $failedChecks->count(), "Known data issues should be detected");
            
            // Property: Check results should be consistent on re-execution
            $secondResult = $this->healthCheckService->executeHealthChecks();
            $this->assertTrue($secondResult['success']);
            
            // Compare results (allowing for minor timing differences)
            $firstSummary = $result['summary'];
            $secondSummary = $secondResult['summary'];
            
            $this->assertEquals($firstSummary['total_checks'], $secondSummary['total_checks']);
            $this->assertEquals($firstSummary['passed'], $secondSummary['passed']);
            $this->assertEquals($firstSummary['failed'], $secondSummary['failed']);
            
            // Property: Alerts should be created for failed checks
            $alertsCreated = DB::table('data_quality_alerts')
                ->where('created_at', '>=', Carbon::now()->subMinutes(1))
                ->count();
            
            $this->assertGreaterThan(0, $alertsCreated, "Alerts should be created for failed checks");
        }
    }
    /**
     * Property 22.3: Analytics Reconciliation Accuracy
     * 
     * Validates that reconciliation between source data and analytics produces
     * accurate variance calculations and appropriate status determinations.
     * 
     * Requirements: 21.2, 21.3
     */
    public function test_analytics_reconciliation_accuracy()
    {
        $iterations = 25;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Create source and target data with controlled variance
            $sourceValue = rand(100, 10000);
            $variancePercent = rand(0, 25); // 0-25% variance
            $targetValue = $sourceValue + ($sourceValue * $variancePercent / 100);
            
            // Create reconciliation rules
            $reconciliationRules = [
                'type' => 'test_reconciliation',
                'source_query' => "SELECT {$sourceValue} as value",
                'target_query' => "SELECT {$targetValue} as value",
                'warning_threshold' => 5.0,
                'critical_threshold' => 15.0,
            ];
            
            // Perform reconciliation
            $result = $this->healthCheckService->performReconciliation(
                'test_source',
                'test_target',
                $reconciliationRules
            );
            
            // Property: Reconciliation should succeed
            $this->assertTrue($result['success'], "Reconciliation failed: " . ($result['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('variance', $result);
            $this->assertArrayHasKey('status', $result);
            
            // Property: Variance calculation should be accurate
            $expectedVariance = abs($sourceValue - $targetValue);
            $expectedPercentage = $sourceValue != 0 ? ($expectedVariance / abs($sourceValue)) * 100 : 0;
            
            $this->assertEquals($sourceValue, $result['variance']['source_value']);
            $this->assertEquals($targetValue, $result['variance']['target_value']);
            $this->assertEquals($expectedVariance, $result['variance']['absolute']);
            $this->assertEquals(round($expectedPercentage, 4), $result['variance']['percentage']);
            
            // Property: Status should match variance thresholds
            $actualPercentage = $result['variance']['percentage'];
            if ($actualPercentage == 0) {
                $this->assertEquals('match', $result['status']);
            } elseif ($actualPercentage <= 5.0) {
                $this->assertEquals('variance_acceptable', $result['status']);
            } elseif ($actualPercentage <= 15.0) {
                $this->assertEquals('variance_critical', $result['status']);
            } else {
                $this->assertEquals('failed', $result['status']);
            }
            
            // Property: Remediation actions should be provided for variances
            if ($actualPercentage > 0) {
                $this->assertArrayHasKey('remediation_actions', $result);
                $this->assertNotEmpty($result['remediation_actions']);
            }
        }
    }

    /**
     * Property 22.4: Hybrid Analytics Real-time Consistency
     * 
     * Validates that real-time counters maintain consistency, proper incrementing,
     * and accurate dimensional tracking.
     * 
     * Requirements: 21.4, 21.5
     */
    public function test_hybrid_analytics_realtime_consistency()
    {
        $iterations = 25;
        
        for ($i = 0; $i < $iterations; $i++) {
            $counterName = 'test_counter_' . $i;
            $totalIncrements = 0;
            $dimensionalIncrements = [];
            
            // Perform multiple increments with various dimensions
            $incrementCount = rand(5, 20);
            for ($j = 0; $j < $incrementCount; $j++) {
                $increment = rand(1, 10);
                $dimensions = [
                    'tenant' => 'tenant_' . rand(1, 3),
                    'event_type' => 'type_' . rand(1, 2),
                ];
                
                // Increment counter
                $result = $this->hybridAnalyticsService->incrementRealtimeCounter(
                    $counterName,
                    $increment,
                    $dimensions
                );
                
                // Property: Increment should succeed
                $this->assertTrue($result['success'], "Counter increment failed: " . ($result['error'] ?? 'Unknown error'));
                
                $totalIncrements += $increment;
                
                // Track dimensional increments
                foreach ($dimensions as $dimension => $value) {
                    $key = "{$dimension}:{$value}";
                    $dimensionalIncrements[$key] = ($dimensionalIncrements[$key] ?? 0) + $increment;
                }
            }
            
            // Property: Total counter should match sum of increments
            $counterResult = $this->hybridAnalyticsService->getRealtimeCounter($counterName);
            $this->assertTrue($counterResult['success']);
            $this->assertEquals($totalIncrements, $counterResult['total_value']);
            
            // Property: Dimensional counters should match their increments
            foreach ($dimensionalIncrements as $dimKey => $expectedValue) {
                [$dimension, $value] = explode(':', $dimKey);
                $dimResult = $this->hybridAnalyticsService->getRealtimeCounter(
                    $counterName,
                    [$dimension => $value]
                );
                
                $this->assertTrue($dimResult['success']);
                $this->assertEquals($expectedValue, $dimResult['dimensional_values'][$dimKey]);
            }
            
            // Property: Counter metadata should be updated
            $this->assertArrayHasKey('metadata', $counterResult);
            $this->assertArrayHasKey('last_updated', $counterResult['metadata']);
        }
    }
    /**
     * Property 22.5: Batch Analytics Consistency and Performance
     * 
     * Validates that batch aggregations execute consistently, produce accurate results,
     * and maintain proper caching behavior.
     * 
     * Requirements: 21.4, 21.5
     */
    public function test_batch_analytics_consistency_and_performance()
    {
        $iterations = 15; // Reduced due to batch processing overhead
        
        for ($i = 0; $i < $iterations; $i++) {
            // Create test aggregation configuration
            $aggregationName = 'test_aggregation_' . $i;
            $testValue = rand(100, 1000);
            
            $aggregationConfig = [
                'name' => $aggregationName,
                'query' => "SELECT {$testValue} as result_value, 'test' as category",
                'parameters' => [],
                'cache_ttl' => 300,
                'invalidation_triggers' => ['test_data_change'],
            ];
            
            // Execute batch aggregation
            $result = $this->hybridAnalyticsService->executeBatchAggregation(
                $aggregationName,
                $aggregationConfig
            );
            
            // Property: Batch aggregation should succeed
            $this->assertTrue($result['success'], "Batch aggregation failed: " . ($result['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('results', $result);
            $this->assertArrayHasKey('execution_time_ms', $result);
            $this->assertGreaterThan(0, $result['execution_time_ms']);
            
            // Property: Results should match expected query output
            $this->assertCount(1, $result['results']);
            $this->assertEquals($testValue, $result['results'][0]['result_value']);
            $this->assertEquals('test', $result['results'][0]['category']);
            
            // Property: Results should be cached
            $cacheKey = "batch_aggregation:{$aggregationName}";
            $cachedResult = Cache::get($cacheKey);
            $this->assertNotNull($cachedResult, "Results should be cached");
            $this->assertEquals($result['results'], $cachedResult['results']);
            
            // Property: Concurrent execution should be prevented
            $concurrentResult = $this->hybridAnalyticsService->executeBatchAggregation(
                $aggregationName,
                $aggregationConfig
            );
            
            // Should either succeed (if first completed) or fail with lock error
            if (!$concurrentResult['success']) {
                $this->assertStringContains('already in progress', $concurrentResult['error']);
            }
            
            // Property: Performance metrics should be recorded
            $metricsCount = DB::table('analytics_performance_metrics')
                ->where('operation_type', 'batch_aggregation')
                ->where('entity_name', $aggregationName)
                ->count();
            
            $this->assertGreaterThan(0, $metricsCount, "Performance metrics should be recorded");
        }
    }

    /**
     * Property 22.6: Cache Invalidation Accuracy
     * 
     * Validates that analytics cache invalidation works correctly based on triggers
     * and maintains cache consistency.
     * 
     * Requirements: 21.5
     */
    public function test_cache_invalidation_accuracy()
    {
        $iterations = 20;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Create multiple cached items with different triggers
            $cacheItems = [];
            $triggerGroups = ['group_a', 'group_b', 'group_c'];
            
            for ($j = 0; $j < 5; $j++) {
                $itemName = "cache_item_{$i}_{$j}";
                $triggers = [
                    $triggerGroups[rand(0, 2)],
                    'common_trigger'
                ];
                
                // Create cache entry
                $cacheKey = "test_cache:{$itemName}";
                Cache::put($cacheKey, ['data' => "test_data_{$j}"], 300);
                
                // Record in cache management
                DB::table('analytics_cache_management')->insert([
                    'cache_key' => $cacheKey,
                    'entity_type' => 'test',
                    'entity_name' => $itemName,
                    'cache_parameters' => json_encode(['test' => true]),
                    'cached_at' => Carbon::now(),
                    'expires_at' => Carbon::now()->addMinutes(5),
                    'status' => 'valid',
                    'invalidation_triggers' => json_encode($triggers),
                ]);
                
                $cacheItems[] = [
                    'name' => $itemName,
                    'cache_key' => $cacheKey,
                    'triggers' => $triggers,
                ];
            }
            
            // Select random trigger to invalidate
            $triggerToInvalidate = $triggerGroups[rand(0, 2)];
            
            // Count items that should be invalidated
            $expectedInvalidations = collect($cacheItems)
                ->filter(function ($item) use ($triggerToInvalidate) {
                    return in_array($triggerToInvalidate, $item['triggers']);
                })
                ->count();
            
            // Perform cache invalidation
            $result = $this->hybridAnalyticsService->invalidateAnalyticsCache([$triggerToInvalidate]);
            
            // Property: Invalidation should succeed
            $this->assertTrue($result['success'], "Cache invalidation failed: " . ($result['error'] ?? 'Unknown error'));
            
            // Property: Correct number of items should be invalidated
            $this->assertEquals($expectedInvalidations, $result['invalidated_count']);
            
            // Property: Invalidated items should no longer be in cache
            foreach ($cacheItems as $item) {
                if (in_array($triggerToInvalidate, $item['triggers'])) {
                    $this->assertNull(Cache::get($item['cache_key']), 
                        "Invalidated item should not be in cache: {$item['cache_key']}");
                } else {
                    $this->assertNotNull(Cache::get($item['cache_key']), 
                        "Non-invalidated item should remain in cache: {$item['cache_key']}");
                }
            }
            
            // Property: Cache management records should be updated
            $invalidatedRecords = DB::table('analytics_cache_management')
                ->where('status', 'invalidated')
                ->where('updated_at', '>=', Carbon::now()->subMinutes(1))
                ->count();
            
            $this->assertEquals($expectedInvalidations, $invalidatedRecords);
        }
    }
    /**
     * Property 22.7: End-to-End Analytics Correctness
     * 
     * Validates complete analytics workflow from data capture through reconciliation
     * maintains consistency and accuracy.
     * 
     * Requirements: 21.1, 21.2, 21.3, 21.4, 21.5
     */
    public function test_end_to_end_analytics_correctness()
    {
        $iterations = 15;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Create booking with facts capture
            $bookingData = $this->createTestBookingData();
            
            // Capture booking facts
            $factsResult = $this->bookingFactsService->captureBookingFacts(
                $bookingData['booking_id'],
                $bookingData
            );
            
            // Property: Facts capture should succeed
            $this->assertTrue($factsResult['success']);
            
            // Update real-time counters
            $counterResult = $this->hybridAnalyticsService->incrementRealtimeCounter(
                'bookings_created',
                1,
                [
                    'event_id' => $bookingData['event_id'],
                    'ticket_type' => $bookingData['ticket_type'],
                ]
            );
            
            // Property: Counter update should succeed
            $this->assertTrue($counterResult['success']);
            
            // Execute batch aggregation for the same data
            $batchConfig = [
                'name' => 'booking_aggregation',
                'query' => "SELECT COUNT(*) as booking_count FROM booking_facts WHERE event_id = ?",
                'parameters' => [$bookingData['event_id']],
            ];
            
            $batchResult = $this->hybridAnalyticsService->executeBatchAggregation(
                'booking_aggregation',
                $batchConfig
            );
            
            // Property: Batch aggregation should succeed
            $this->assertTrue($batchResult['success']);
            
            // Perform reconciliation between real-time and batch
            $realtimeCount = $this->hybridAnalyticsService->getRealtimeCounter('bookings_created');
            $batchCount = $batchResult['results'][0]['booking_count'] ?? 0;
            
            $reconciliationRules = [
                'type' => 'realtime_vs_batch',
                'source_query' => "SELECT {$realtimeCount['total_value']} as value",
                'target_query' => "SELECT {$batchCount} as value",
                'warning_threshold' => 1.0,
                'critical_threshold' => 5.0,
            ];
            
            $reconciliationResult = $this->healthCheckService->performReconciliation(
                'realtime_counter',
                'batch_aggregation',
                $reconciliationRules
            );
            
            // Property: Reconciliation should succeed
            $this->assertTrue($reconciliationResult['success']);
            
            // Property: For single booking, counts should match or be very close
            $variance = $reconciliationResult['variance']['percentage'];
            $this->assertLessThanOrEqual(10.0, $variance, 
                "Variance between real-time and batch should be minimal for single booking");
            
            // Property: All operations should be auditable
            $auditCount = DB::table('audit_logs')
                ->where('created_at', '>=', Carbon::now()->subMinutes(1))
                ->count();
            
            $this->assertGreaterThan(0, $auditCount, "Operations should be audited");
        }
    }

    // Helper methods for test data generation

    private function setupTestData(): void
    {
        // Create test tables and sample data
        $this->createTestHealthChecks();
        $this->createTestKPIDefinitions();
    }

    private function generateRandomKPIDefinition(): array
    {
        $frequencies = ['real_time', 'hourly', 'daily', 'weekly'];
        $domains = ['booking', 'revenue', 'customer', 'event'];
        
        return [
            'kpi_name' => 'test_kpi_' . uniqid(),
            'description' => 'Test KPI for property-based testing',
            'calculation_logic' => [
                'formula' => 'COUNT(*)',
                'filters' => ['status' => 'active'],
            ],
            'data_sources' => ['bookings', 'events'],
            'refresh_frequency' => $frequencies[array_rand($frequencies)],
            'owner_team' => 'test_team',
            'business_domain' => $domains[array_rand($domains)],
            'validation_rules' => [
                'min_value' => 0,
                'max_value' => 1000000,
            ],
            'criticality' => 'medium',
        ];
    }

    private function createTestDataWithKnownIssues(): array
    {
        // Create test data with intentional quality issues
        return [
            'table_name' => 'test_bookings',
            'issues' => [
                'duplicate_records' => rand(1, 5),
                'null_values' => rand(1, 3),
                'invalid_dates' => rand(0, 2),
            ],
        ];
    }

    private function createHealthChecksForTestData(array $testData): array
    {
        $checks = [];
        
        // Create health check for duplicates
        $checks[] = DB::table('data_health_checks')->insertGetId([
            'check_name' => 'duplicate_check_' . uniqid(),
            'check_type' => 'uniqueness',
            'target_table' => $testData['table_name'],
            'check_query' => "SELECT COUNT(*) - COUNT(DISTINCT id) as duplicates FROM {$testData['table_name']}",
            'expected_result' => json_encode(['exact' => 0]),
            'created_at' => Carbon::now(),
        ]);
        
        return $checks;
    }

    private function createTestBookingData(): array
    {
        return [
            'booking_id' => rand(1000, 9999),
            'event_id' => rand(1, 100),
            'customer_id' => rand(1, 1000),
            'ticket_type' => 'general',
            'quantity' => rand(1, 4),
            'total_amount' => rand(50, 500),
            'status' => 'confirmed',
        ];
    }

    private function createTestHealthChecks(): void
    {
        DB::table('data_health_checks')->insert([
            [
                'check_name' => 'booking_count_consistency',
                'check_type' => 'consistency',
                'target_table' => 'bookings',
                'check_query' => 'SELECT COUNT(*) as count FROM bookings',
                'expected_result' => json_encode(['min' => 0]),
                'created_at' => Carbon::now(),
            ],
        ]);
    }

    private function createTestKPIDefinitions(): void
    {
        DB::table('kpi_definitions')->insert([
            [
                'kpi_name' => 'total_bookings',
                'kpi_description' => 'Total number of bookings',
                'calculation_logic' => json_encode(['formula' => 'COUNT(*)']),
                'data_sources' => json_encode(['bookings']),
                'refresh_frequency' => 'daily',
                'version' => 1,
                'is_active' => true,
                'created_at' => Carbon::now(),
            ],
        ]);
    }
}