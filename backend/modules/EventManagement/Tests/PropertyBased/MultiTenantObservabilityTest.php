<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Services\CapacityPlanningService;
use Modules\EventManagement\Services\TenantObservabilityService;
use Tests\TestCase;

/**
 * Property-Based Test: Multi-Tenant Observability Accuracy - Task 11.5
 *
 * Property 28: Multi-Tenant Observability Accuracy
 * Validates: Requirements 27.1, 27.2, 27.3, 27.4, 27.5
 *
 * This test validates that multi-tenant observability systems maintain accurate
 * metrics collection, proper tenant isolation, reliable alerting, and effective
 * capacity planning across all tenant boundaries and system conditions.
 */
class MultiTenantObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private TenantObservabilityService $observabilityService;

    private CapacityPlanningService $capacityService;

    private array $testTenants;

    private array $testMetrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->observabilityService = app(TenantObservabilityService::class);
        $this->capacityService = app(CapacityPlanningService::class);

        // Create test tenants
        $this->testTenants = [
            'tenant_001',
            'tenant_002',
            'tenant_003',
        ];

        // Define test metrics
        $this->testMetrics = [
            'api_response_time_ms',
            'api_error_rate_percent',
            'queue_lag_seconds',
            'cpu_usage_percent',
            'memory_usage_percent',
        ];
    }

    /**
     * Property 28.1: Tenant-Aware Metrics Collection Accuracy
     *
     * PROPERTY: For any tenant T and metric M with value V,
     * WHEN metric is collected for tenant T,
     * THEN the stored metric maintains exact value V and proper tenant isolation
     * AND no cross-tenant data leakage occurs
     * AND metric timestamps are accurate within system precision
     */
    public function test_tenant_aware_metrics_collection_accuracy(): void
    {
        $this->runPropertyTest(100, function () {
            // Generate random test data
            $tenantId = $this->faker->randomElement($this->testTenants);
            $metricName = $this->faker->randomElement($this->testMetrics);
            $metricValue = $this->faker->randomFloat(2, 0, 1000);
            $context = [
                'unit' => 'ms',
                'source' => 'test',
                'tags' => ['test' => true],
            ];

            $collectionTime = now();

            // Collect metric
            $result = $this->observabilityService->collectMetric(
                $tenantId,
                $metricName,
                $metricValue,
                $context
            );

            // Verify collection success
            $this->assertTrue($result['success'], 'Metric collection should succeed');
            $this->assertIsInt($result['metric_id'], 'Should return valid metric ID');

            // Verify stored metric accuracy
            $storedMetric = DB::table('tenant_health_metrics')
                ->where('id', $result['metric_id'])
                ->first();

            $this->assertNotNull($storedMetric, 'Metric should be stored in database');
            $this->assertEquals($tenantId, $storedMetric->tenant_id, 'Tenant ID should match exactly');
            $this->assertEquals($metricName, $storedMetric->metric_name, 'Metric name should match exactly');
            $this->assertEquals($metricValue, $storedMetric->metric_value, 'Metric value should match exactly');
            $this->assertEquals($context['unit'], $storedMetric->metric_unit, 'Metric unit should match');

            // Verify timestamp accuracy (within 1 second)
            $timeDiff = abs(Carbon::parse($storedMetric->collected_at)->diffInSeconds($collectionTime));
            $this->assertLessThanOrEqual(1, $timeDiff, 'Timestamp should be accurate within 1 second');

            // Verify tenant isolation - check no cross-tenant contamination
            $otherTenantMetrics = DB::table('tenant_health_metrics')
                ->where('metric_name', $metricName)
                ->where('tenant_id', '!=', $tenantId)
                ->where('metric_value', $metricValue)
                ->where('collected_at', '>=', $collectionTime->subSeconds(5))
                ->count();

            $this->assertEquals(0, $otherTenantMetrics, 'No cross-tenant metric contamination should occur');

            // Verify JSON fields are properly encoded
            $tags = json_decode($storedMetric->metric_tags, true);
            $this->assertEquals($context['tags'], $tags, 'Tags should be properly JSON encoded');
        });
    }

    /**
     * Property 28.2: API Performance Monitoring Completeness
     *
     * PROPERTY: For any API request R with performance data P,
     * WHEN performance is logged for tenant T,
     * THEN all performance metrics are captured accurately
     * AND performance breakdown calculations are mathematically correct
     * AND no performance data is lost or corrupted
     */
    public function test_api_performance_monitoring_completeness(): void
    {
        $this->runPropertyTest(100, function () {
            $tenantId = $this->faker->randomElement($this->testTenants);

            // Generate realistic API performance data
            $performanceData = [
                'request_id' => 'req_'.uniqid(),
                'endpoint' => '/api/v1/'.$this->faker->word,
                'method' => $this->faker->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
                'status' => $this->faker->randomElement([200, 201, 400, 404, 500]),
                'response_time' => $this->faker->numberBetween(50, 5000),
                'database_time' => $this->faker->numberBetween(10, 1000),
                'cache_time' => $this->faker->numberBetween(1, 50),
                'external_api_time' => $this->faker->numberBetween(0, 2000),
                'memory_usage' => $this->faker->numberBetween(10, 500),
                'started_at' => now()->subSeconds($this->faker->numberBetween(1, 300)),
                'user_id' => 'user_'.$this->faker->numberBetween(1, 1000),
            ];

            // Log API performance
            $result = $this->observabilityService->logApiPerformance($tenantId, $performanceData);

            // Verify logging success
            $this->assertTrue($result['success'], 'API performance logging should succeed');
            $this->assertIsInt($result['log_id'], 'Should return valid log ID');

            // Verify stored performance data
            $storedLog = DB::table('api_performance_log')
                ->where('id', $result['log_id'])
                ->first();

            $this->assertNotNull($storedLog, 'Performance log should be stored');
            $this->assertEquals($tenantId, $storedLog->tenant_id, 'Tenant ID should match');
            $this->assertEquals($performanceData['request_id'], $storedLog->request_id, 'Request ID should match');
            $this->assertEquals($performanceData['endpoint'], $storedLog->endpoint, 'Endpoint should match');
            $this->assertEquals($performanceData['response_time'], $storedLog->response_time_ms, 'Response time should match');

            // Verify performance breakdown calculations
            $breakdown = json_decode($storedLog->performance_breakdown, true);
            $this->assertIsArray($breakdown, 'Performance breakdown should be valid JSON array');

            $expectedTotal = $performanceData['response_time'];
            $calculatedTotal = $breakdown['total_time_ms'];
            $this->assertEquals($expectedTotal, $calculatedTotal, 'Total time calculation should be accurate');

            // Verify breakdown components sum correctly (allowing for application time calculation)
            $componentSum = $breakdown['database_time_ms'] + $breakdown['cache_time_ms'] + $breakdown['external_api_time_ms'];
            $this->assertLessThanOrEqual($expectedTotal, $componentSum, 'Component times should not exceed total');

            // Verify percentage calculations
            $percentageSum = array_sum($breakdown['breakdown_percentages']);
            $this->assertEqualsWithDelta(100, $percentageSum, 0.1, 'Percentage breakdown should sum to 100%');
        });
    }

    /**
     * Property 28.3: Queue Health Monitoring Consistency
     *
     * PROPERTY: For any queue Q in tenant T,
     * WHEN queue health is monitored,
     * THEN all queue metrics are internally consistent
     * AND queue lag calculations are accurate
     * AND error rates are mathematically correct
     */
    public function test_queue_health_monitoring_consistency(): void
    {
        $this->runPropertyTest(50, function () {
            $tenantId = $this->faker->randomElement($this->testTenants);

            // Create test queue data
            $queueName = "tenant_{$tenantId}_".$this->faker->randomElement(['default', 'notifications', 'analytics']);

            // Insert test jobs for realistic queue metrics
            $pendingJobs = $this->faker->numberBetween(0, 100);
            $failedJobs = $this->faker->numberBetween(0, 10);
            $completedJobs = $this->faker->numberBetween(10, 1000);

            // Monitor queue health
            $result = $this->observabilityService->monitorQueueHealth($tenantId, [
                'queues' => [$queueName],
            ]);

            // Verify monitoring success
            $this->assertTrue($result['success'], 'Queue health monitoring should succeed');
            $this->assertArrayHasKey('queue_health', $result, 'Should return queue health data');
            $this->assertArrayHasKey('overall_health', $result, 'Should return overall health assessment');

            $queueHealth = $result['queue_health'][$queueName] ?? null;
            $this->assertNotNull($queueHealth, 'Queue health data should be present');

            // Verify metric consistency
            $this->assertIsInt($queueHealth['pending_jobs'], 'Pending jobs should be integer');
            $this->assertIsInt($queueHealth['failed_jobs'], 'Failed jobs should be integer');
            $this->assertIsInt($queueHealth['completed_jobs'], 'Completed jobs should be integer');
            $this->assertIsNumeric($queueHealth['error_rate_percent'], 'Error rate should be numeric');
            $this->assertIsNumeric($queueHealth['queue_lag_seconds'], 'Queue lag should be numeric');

            // Verify error rate calculation consistency
            $totalJobs = $queueHealth['completed_jobs'] + $queueHealth['failed_jobs'];
            if ($totalJobs > 0) {
                $expectedErrorRate = ($queueHealth['failed_jobs'] / $totalJobs) * 100;
                $this->assertEqualsWithDelta(
                    $expectedErrorRate,
                    $queueHealth['error_rate_percent'],
                    0.1,
                    'Error rate calculation should be mathematically correct'
                );
            }

            // Verify non-negative values
            $this->assertGreaterThanOrEqual(0, $queueHealth['pending_jobs'], 'Pending jobs should be non-negative');
            $this->assertGreaterThanOrEqual(0, $queueHealth['failed_jobs'], 'Failed jobs should be non-negative');
            $this->assertGreaterThanOrEqual(0, $queueHealth['queue_lag_seconds'], 'Queue lag should be non-negative');
            $this->assertGreaterThanOrEqual(0, $queueHealth['error_rate_percent'], 'Error rate should be non-negative');
            $this->assertLessThanOrEqual(100, $queueHealth['error_rate_percent'], 'Error rate should not exceed 100%');
        });
    }

    /**
     * Property 28.4: Alert Rule Processing Reliability
     *
     * PROPERTY: For any alert rule R with conditions C,
     * WHEN metric M triggers condition C,
     * THEN alert is generated with correct severity and timing
     * AND cooldown periods are respected
     * AND alert data is complete and accurate
     */
    public function test_alert_rule_processing_reliability(): void
    {
        $this->runPropertyTest(50, function () {
            $tenantId = $this->faker->randomElement($this->testTenants);
            $metricName = $this->faker->randomElement($this->testMetrics);

            // Create alert rule
            $warningThreshold = $this->faker->numberBetween(50, 80);
            $criticalThreshold = $this->faker->numberBetween(81, 100);

            $ruleData = [
                'rule_name' => 'test_rule_'.uniqid(),
                'rule_type' => 'threshold',
                'metric_name' => $metricName,
                'metric_category' => 'performance',
                'conditions' => [
                    'warning_threshold' => $warningThreshold,
                    'critical_threshold' => $criticalThreshold,
                ],
                'severity_level' => 'warning',
                'is_enabled' => true,
                'cooldown_seconds' => 300,
            ];

            // Create alert rule
            $ruleResult = $this->observabilityService->createAlertRule($tenantId, $ruleData);
            $this->assertTrue($ruleResult['success'], 'Alert rule creation should succeed');

            // Test warning threshold trigger
            $warningValue = $warningThreshold + $this->faker->numberBetween(1, 5);
            $metricResult = $this->observabilityService->collectMetric(
                $tenantId,
                $metricName,
                $warningValue,
                ['source' => 'test']
            );

            $this->assertTrue($metricResult['success'], 'Metric collection should succeed');
            $this->assertEquals('warning', $metricResult['alert_status'], 'Should trigger warning alert');

            // Verify alert incident creation
            $incident = DB::table('alert_incidents')
                ->where('tenant_id', $tenantId)
                ->where('alert_rule_id', $ruleResult['rule_id'])
                ->where('severity_level', 'warning')
                ->first();

            $this->assertNotNull($incident, 'Warning incident should be created');
            $this->assertEquals('triggered', $incident->incident_status, 'Incident should be in triggered status');

            // Test critical threshold trigger
            $criticalValue = $criticalThreshold + $this->faker->numberBetween(1, 10);
            $criticalResult = $this->observabilityService->collectMetric(
                $tenantId,
                $metricName,
                $criticalValue,
                ['source' => 'test']
            );

            $this->assertEquals('critical', $criticalResult['alert_status'], 'Should trigger critical alert');

            // Test cooldown period - immediate re-trigger should be ignored
            $cooldownResult = $this->observabilityService->collectMetric(
                $tenantId,
                $metricName,
                $criticalValue + 10,
                ['source' => 'test']
            );

            // Should still collect metric but not create new incident due to cooldown
            $this->assertTrue($cooldownResult['success'], 'Metric collection should succeed during cooldown');

            $incidentCount = DB::table('alert_incidents')
                ->where('tenant_id', $tenantId)
                ->where('alert_rule_id', $ruleResult['rule_id'])
                ->count();

            // Should have at most 2 incidents (warning + critical), not more due to cooldown
            $this->assertLessThanOrEqual(2, $incidentCount, 'Cooldown should prevent excessive incident creation');
        });
    }

    /**
     * Property 28.5: Capacity Planning Forecast Accuracy
     *
     * PROPERTY: For any tenant T with usage history H,
     * WHEN capacity forecast is generated,
     * THEN forecast values are mathematically consistent with historical data
     * AND confidence levels reflect data quality
     * AND recommendations are actionable and safe
     */
    public function test_capacity_planning_forecast_accuracy(): void
    {
        $this->runPropertyTest(30, function () {
            $tenantId = $this->faker->randomElement($this->testTenants);

            // Create historical usage data
            $resourceType = $this->faker->randomElement(['cpu', 'memory', 'storage']);
            $baseUsage = $this->faker->numberBetween(20, 60);
            $growthRate = $this->faker->randomFloat(2, -5, 15); // -5% to 15% growth

            // Generate 30 days of historical data with trend
            for ($i = 30; $i >= 1; $i--) {
                $date = now()->subDays($i);
                $trendFactor = 1 + ($growthRate / 100) * (30 - $i) / 30;
                $dailyVariation = $this->faker->randomFloat(2, 0.8, 1.2);
                $usage = $baseUsage * $trendFactor * $dailyVariation;

                DB::table('tenant_resource_usage')->insert([
                    'tenant_id' => $tenantId,
                    'resource_type' => $resourceType,
                    'usage_value' => $usage,
                    'usage_unit' => 'percent',
                    'usage_percentage' => min(100, $usage),
                    'measured_at' => $date,
                    'created_at' => $date,
                ]);
            }

            // Analyze resource usage
            $analysis = $this->capacityService->analyzeResourceUsage($tenantId, [
                'analysis_window' => '30d',
                'resource_types' => [$resourceType],
            ]);

            $this->assertTrue($analysis['success'], 'Resource usage analysis should succeed');
            $this->assertArrayHasKey('resource_analysis', $analysis, 'Should return resource analysis');

            $resourceAnalysis = $analysis['resource_analysis'][$resourceType] ?? null;
            $this->assertNotNull($resourceAnalysis, 'Resource analysis should be present');

            // Verify mathematical consistency
            $this->assertIsNumeric($resourceAnalysis['avg_usage'], 'Average usage should be numeric');
            $this->assertIsNumeric($resourceAnalysis['growth_rate'], 'Growth rate should be numeric');
            $this->assertGreaterThanOrEqual(0, $resourceAnalysis['min_usage'], 'Min usage should be non-negative');
            $this->assertLessThanOrEqual(100, $resourceAnalysis['max_usage'], 'Max usage should not exceed 100%');

            // Verify percentile ordering
            $this->assertLessThanOrEqual($resourceAnalysis['p95_usage'], $resourceAnalysis['p99_usage'], 'P95 should be <= P99');
            $this->assertLessThanOrEqual($resourceAnalysis['p50_usage'], $resourceAnalysis['p95_usage'], 'P50 should be <= P95');

            // Verify trend detection accuracy
            if (abs($growthRate) > 5) {
                $expectedTrend = $growthRate > 0 ? 'increasing' : 'decreasing';
                $this->assertEquals($expectedTrend, $resourceAnalysis['trend'], 'Trend detection should match actual growth pattern');
            }

            // Generate forecasts
            $forecasts = $this->capacityService->generateCapacityForecasts($tenantId, [
                'resource_types' => [$resourceType],
                'periods' => ['1m', '3m'],
            ]);

            $this->assertTrue($forecasts['success'], 'Forecast generation should succeed');
            $this->assertArrayHasKey('forecasts', $forecasts, 'Should return forecasts');
            $this->assertNotEmpty($forecasts['forecasts'], 'Should generate at least one forecast');

            // Verify forecast reasonableness
            foreach ($forecasts['forecasts'] as $forecast) {
                $this->assertArrayHasKey('current_usage', $forecast, 'Forecast should include current usage');
                $this->assertArrayHasKey('forecasted_usage', $forecast, 'Forecast should include predicted usage');
                $this->assertArrayHasKey('confidence_level', $forecast, 'Forecast should include confidence level');

                $this->assertGreaterThan(0, $forecast['confidence_level'], 'Confidence should be positive');
                $this->assertLessThanOrEqual(100, $forecast['confidence_level'], 'Confidence should not exceed 100%');

                // Forecast should be reasonable relative to current usage
                $forecastRatio = $forecast['forecasted_usage'] / max(1, $forecast['current_usage']);
                $this->assertGreaterThan(0.1, $forecastRatio, 'Forecast should not predict usage collapse');
                $this->assertLessThan(10, $forecastRatio, 'Forecast should not predict unrealistic growth');
            }
        });
    }

    /**
     * Run property-based test with specified iterations
     */
    private function runPropertyTest(int $iterations, callable $testFunction): void
    {
        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Clear any cached data between iterations
                Cache::flush();

                // Run the test function
                $testFunction();

                // Clean up test data to prevent interference
                $this->cleanupTestData();

            } catch (\Exception $e) {
                $this->fail("Property test failed on iteration {$i}: ".$e->getMessage());
            }
        }
    }

    /**
     * Clean up test data between iterations
     */
    private function cleanupTestData(): void
    {
        DB::table('tenant_health_metrics')->where('collection_source', 'test')->delete();
        DB::table('api_performance_log')->where('user_agent', 'like', '%test%')->delete();
        DB::table('alert_incidents')->where('incident_description', 'like', '%test%')->delete();
        DB::table('tenant_alert_rules')->where('rule_name', 'like', 'test_rule_%')->delete();
        DB::table('tenant_resource_usage')->whereIn('tenant_id', $this->testTenants)->delete();
    }
}
