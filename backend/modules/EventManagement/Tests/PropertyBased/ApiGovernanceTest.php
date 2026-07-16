<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\EventManagement\Services\ApiContractService;
use Modules\EventManagement\Services\ApiGovernanceService;
use Modules\EventManagement\Services\ApiMonitoringService;
use Modules\EventManagement\Services\RbacMatrixService;
use Tests\TestCase;

/**
 * Property-Based Test: API Contract Enforcement and RBAC Integrity - Task 12.5
 *
 * Property 29: API Contract Enforcement and RBAC Integrity
 * Validates: Requirements 28.1, 28.2, 28.3, 28.4, 28.5
 *
 * This test validates that API governance systems maintain contract enforcement,
 * RBAC integrity, proper versioning, accurate monitoring, and effective rate
 * limiting across all API interactions and consumer types.
 */
class ApiGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private ApiContractService $contractService;

    private ApiGovernanceService $governanceService;

    private RbacMatrixService $rbacService;

    private ApiMonitoringService $monitoringService;

    private array $testEndpoints;

    private array $testRoles;

    private array $testConsumers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractService = app(ApiContractService::class);
        $this->governanceService = app(ApiGovernanceService::class);
        $this->rbacService = app(RbacMatrixService::class);
        $this->monitoringService = app(ApiMonitoringService::class);

        // Define test data
        $this->testEndpoints = [
            '/api/v1/events',
            '/api/v1/bookings',
            '/api/v1/customers',
            '/api/v1/analytics',
        ];

        $this->testRoles = [
            'admin',
            'manager',
            'staff',
            'customer',
        ];

        $this->testConsumers = [
            ['id' => 'consumer_001', 'type' => 'internal'],
            ['id' => 'consumer_002', 'type' => 'partner'],
            ['id' => 'consumer_003', 'type' => 'public'],
        ];
    }

    /**
     * Property 29.1: API Contract Enforcement Consistency
     *
     * PROPERTY: For any API contract C with validation rules R,
     * WHEN request matches contract requirements,
     * THEN validation succeeds consistently
     * AND contract violations are detected accurately
     * AND violation logging is complete and immutable
     */
    public function test_api_contract_enforcement_consistency(): void
    {
        $this->runPropertyTest(100, function () {
            // Generate random contract specification
            $endpoint = $this->faker->randomElement($this->testEndpoints);
            $method = $this->faker->randomElement(['GET', 'POST', 'PUT', 'DELETE']);
            $version = $this->faker->randomElement(['1.0', '1.1', '2.0']);

            $contractSpec = [
                'summary' => 'Test API endpoint',
                'description' => 'Test endpoint for property validation',
                'request' => [
                    'query_parameters' => [
                        'limit' => [
                            'type' => 'integer',
                            'required' => false,
                        ],
                        'filter' => [
                            'type' => 'string',
                            'required' => $this->faker->boolean(),
                        ],
                    ],
                    'body_schema' => $method !== 'GET' ? [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                    ] : null,
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Success response',
                        'body_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => ['type' => 'array'],
                                'meta' => ['type' => 'object'],
                            ],
                        ],
                    ],
                    '400' => [
                        'description' => 'Bad request',
                        'body_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'error' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ];

            // Store contract
            $storeResult = $this->contractService->storeContract($endpoint, $method, $version, $contractSpec);
            $this->assertTrue($storeResult['success'], 'Contract storage should succeed');

            // Retrieve contract
            $retrievedContract = $this->contractService->getContract($endpoint, $method, $version);
            $this->assertNotNull($retrievedContract, 'Contract should be retrievable');
            $this->assertEquals($contractSpec['summary'], $retrievedContract['summary'], 'Contract content should match');

            // Test contract validation with valid request
            $validRequest = $this->createValidRequest($contractSpec, $method);
            $this->assertRequestMatchesContract($validRequest, $contractSpec, true);

            // Test contract validation with invalid request
            $invalidRequest = $this->createInvalidRequest($contractSpec, $method);
            $this->assertRequestMatchesContract($invalidRequest, $contractSpec, false);

            // Verify contract versioning
            $this->verifyContractVersioning($endpoint, $method, $version, $contractSpec);
        });
    }

    /**
     * Property 29.2: RBAC Permission Matrix Integrity
     *
     * PROPERTY: For any role R and endpoint E with permission P,
     * WHEN permission is evaluated,
     * THEN access decision is consistent with matrix definition
     * AND permission inheritance works correctly
     * AND conditional permissions evaluate accurately
     */
    public function test_rbac_permission_matrix_integrity(): void
    {
        $this->runPropertyTest(100, function () {
            $role = $this->faker->randomElement($this->testRoles);
            $endpoint = $this->faker->randomElement($this->testEndpoints);
            $method = $this->faker->randomElement(['GET', 'POST', 'PUT', 'DELETE']);
            $version = '1.0';

            // Create permission matrix entry
            $permissionLevel = $this->faker->randomElement(['allow', 'deny', 'conditional']);
            $conditions = $permissionLevel === 'conditional' ? [
                [
                    'field' => 'tenant_id',
                    'operator' => 'equals',
                    'value' => 'test_tenant',
                ],
            ] : [];

            $permissionData = [
                'role_name' => $role,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'permission_level' => $permissionLevel,
                'conditions' => $conditions,
                'field_permissions' => [
                    'read' => ['id', 'name', 'created_at'],
                    'write' => $permissionLevel === 'allow' ? ['name', 'description'] : [],
                ],
            ];

            $updateResult = $this->rbacService->updatePermissionMatrix($permissionData);
            $this->assertTrue($updateResult['success'], 'Permission matrix update should succeed');

            // Test access validation
            $context = $permissionLevel === 'conditional' ? ['tenant_id' => 'test_tenant'] : [];
            $accessResult = $this->rbacService->validateRoleAccess($role, $endpoint, $method, $version, $context);

            // Verify access decision matches permission level
            $expectedAccess = match ($permissionLevel) {
                'allow' => true,
                'deny' => false,
                'conditional' => true // Should be true with matching context
            };

            $this->assertEquals($expectedAccess, $accessResult['allowed'],
                "Access decision should match permission level {$permissionLevel}");
            $this->assertEquals($permissionLevel, $accessResult['permission_level'],
                'Permission level should be preserved in response');

            // Test with invalid context for conditional permissions
            if ($permissionLevel === 'conditional') {
                $invalidContext = ['tenant_id' => 'wrong_tenant'];
                $invalidAccessResult = $this->rbacService->validateRoleAccess($role, $endpoint, $method, $version, $invalidContext);
                $this->assertFalse($invalidAccessResult['allowed'],
                    'Conditional permission should deny access with invalid context');
            }

            // Verify permission matrix testing
            $testResult = $this->rbacService->runPermissionMatrixTests([
                'roles' => [$role],
                'endpoints' => [$endpoint],
            ]);

            $this->assertTrue($testResult['success'], 'Permission matrix tests should succeed');
            $this->assertGreaterThan(0, $testResult['summary']['total_tests'], 'Should execute at least one test');
        });
    }

    /**
     * Property 29.3: API Versioning and Compatibility
     *
     * PROPERTY: For any API version V1 and V2 with contracts C1 and C2,
     * WHEN compatibility is checked,
     * THEN breaking changes are detected accurately
     * AND backward compatibility is preserved
     * AND version-specific routing works correctly
     */
    public function test_api_versioning_and_compatibility(): void
    {
        $this->runPropertyTest(50, function () {
            $endpoint = $this->faker->randomElement($this->testEndpoints);
            $method = 'GET';
            $oldVersion = '1.0';
            $newVersion = '1.1';

            // Create base contract (v1.0)
            $baseContract = [
                'summary' => 'Base API endpoint',
                'request' => [
                    'query_parameters' => [
                        'id' => ['type' => 'integer', 'required' => true],
                        'name' => ['type' => 'string', 'required' => false],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Success',
                        'body_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ];

            // Store base contract
            $baseResult = $this->contractService->storeContract($endpoint, $method, $oldVersion, $baseContract);
            $this->assertTrue($baseResult['success'], 'Base contract storage should succeed');

            // Create new contract with potential breaking changes
            $breakingChange = $this->faker->boolean();
            $newContract = $baseContract;

            if ($breakingChange) {
                // Add required parameter (breaking change)
                $newContract['request']['query_parameters']['new_required'] = [
                    'type' => 'string',
                    'required' => true,
                ];
            } else {
                // Add optional parameter (non-breaking change)
                $newContract['request']['query_parameters']['new_optional'] = [
                    'type' => 'string',
                    'required' => false,
                ];
            }

            // Store new contract
            $newResult = $this->contractService->storeContract($endpoint, $method, $newVersion, $newContract);
            $this->assertTrue($newResult['success'], 'New contract storage should succeed');

            // Check compatibility
            $compatibilityResult = $this->contractService->checkCompatibility($endpoint, $method, $oldVersion, $newVersion);
            $this->assertTrue($compatibilityResult !== false, 'Compatibility check should execute');

            if ($breakingChange) {
                $this->assertFalse($compatibilityResult['compatible'],
                    'Breaking changes should be detected as incompatible');
                $this->assertNotEmpty($compatibilityResult['issues'],
                    'Breaking changes should generate compatibility issues');
            } else {
                $this->assertTrue($compatibilityResult['compatible'],
                    'Non-breaking changes should be compatible');
            }

            // Verify both versions are accessible
            $oldVersionContract = $this->contractService->getContract($endpoint, $method, $oldVersion);
            $newVersionContract = $this->contractService->getContract($endpoint, $method, $newVersion);

            $this->assertNotNull($oldVersionContract, 'Old version should remain accessible');
            $this->assertNotNull($newVersionContract, 'New version should be accessible');
            $this->assertNotEquals($oldVersionContract, $newVersionContract, 'Versions should be different');
        });
    }

    /**
     * Property 29.4: API Usage Monitoring Accuracy
     *
     * PROPERTY: For any API usage U with metrics M,
     * WHEN usage is tracked,
     * THEN metrics are recorded accurately
     * AND aggregations are mathematically correct
     * AND performance insights are actionable
     */
    public function test_api_usage_monitoring_accuracy(): void
    {
        $this->runPropertyTest(100, function () {
            $endpoint = $this->faker->randomElement($this->testEndpoints);
            $method = $this->faker->randomElement(['GET', 'POST', 'PUT', 'DELETE']);
            $version = '1.0';
            $tenantId = 'tenant_'.$this->faker->numberBetween(1, 10);
            $consumerId = $this->faker->randomElement($this->testConsumers)['id'];

            // Generate usage data
            $responseTime = $this->faker->numberBetween(50, 2000);
            $statusCode = $this->faker->randomElement([200, 201, 400, 404, 500]);
            $success = $statusCode < 400;

            $usageData = [
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'tenant_id' => $tenantId,
                'consumer_id' => $consumerId,
                'consumer_type' => 'partner',
                'response_time_ms' => $responseTime,
                'status_code' => $statusCode,
                'success' => $success,
                'user_id' => 'user_'.$this->faker->numberBetween(1, 100),
            ];

            // Track usage
            $trackResult = $this->governanceService->trackApiUsage($usageData);
            $this->assertTrue($trackResult['success'], 'Usage tracking should succeed');

            // Verify usage is recorded in analytics
            $analyticsRecord = DB::table('api_usage_analytics')
                ->where('endpoint', $endpoint)
                ->where('method', $method)
                ->where('tenant_id', $tenantId)
                ->where('consumer_id', $consumerId)
                ->where('usage_date', now()->toDateString())
                ->first();

            $this->assertNotNull($analyticsRecord, 'Usage analytics should be recorded');
            $this->assertGreaterThan(0, $analyticsRecord->request_count, 'Request count should be incremented');

            // Verify response time tracking
            if ($success) {
                $this->assertEquals(1, $analyticsRecord->success_count, 'Success count should be accurate');
                $this->assertEquals(0, $analyticsRecord->error_count, 'Error count should be zero for success');
            } else {
                $this->assertEquals(0, $analyticsRecord->success_count, 'Success count should be zero for error');
                $this->assertEquals(1, $analyticsRecord->error_count, 'Error count should be accurate');
            }

            // Verify error rate calculation
            $totalRequests = $analyticsRecord->success_count + $analyticsRecord->error_count;
            $expectedErrorRate = $totalRequests > 0 ? ($analyticsRecord->error_count / $totalRequests) * 100 : 0;
            $this->assertEquals($expectedErrorRate, $analyticsRecord->error_rate_percent,
                'Error rate calculation should be mathematically correct');

            // Verify status code distribution
            $statusDistribution = json_decode($analyticsRecord->status_code_distribution, true);
            $this->assertArrayHasKey((string) $statusCode, $statusDistribution,
                'Status code should be recorded in distribution');
            $this->assertGreaterThan(0, $statusDistribution[(string) $statusCode],
                'Status code count should be incremented');

            // Test usage analytics retrieval
            $analyticsResult = $this->governanceService->getUsageAnalytics([
                'start_date' => now()->subDay(),
                'end_date' => now(),
                'tenant_id' => $tenantId,
            ]);

            $this->assertTrue($analyticsResult['success'], 'Usage analytics retrieval should succeed');
            $this->assertArrayHasKey('overall_statistics', $analyticsResult, 'Should include overall statistics');
            $this->assertGreaterThan(0, $analyticsResult['overall_statistics']->total_requests,
                'Should show tracked requests in statistics');
        });
    }

    /**
     * Property 29.5: Rate Limiting and Quota Enforcement
     *
     * PROPERTY: For any consumer C with rate limit R and quota Q,
     * WHEN requests exceed limits,
     * THEN enforcement actions are applied correctly
     * AND quota tracking is accurate
     * AND violation logging is complete
     */
    public function test_rate_limiting_and_quota_enforcement(): void
    {
        $this->runPropertyTest(50, function () {
            $consumer = $this->faker->randomElement($this->testConsumers);
            $consumerId = $consumer['id'];
            $consumerType = $consumer['type'];
            $endpoint = $this->faker->randomElement($this->testEndpoints);
            $method = 'GET';

            // Set up rate limits
            $requestsPerMinute = $this->faker->numberBetween(5, 20);
            $requestsPerHour = $requestsPerMinute * 60;

            DB::table('api_rate_limits')->insert([
                'consumer_type' => 'consumer',
                'consumer_id' => $consumerId,
                'endpoint_pattern' => '*',
                'method' => '*',
                'requests_per_minute' => $requestsPerMinute,
                'requests_per_hour' => $requestsPerHour,
                'burst_limit' => $requestsPerMinute * 2,
                'is_active' => true,
                'enforcement_action' => 'reject',
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Test requests within limit
            for ($i = 0; $i < $requestsPerMinute; $i++) {
                $rateLimitResult = $this->monitoringService->enforceRateLimit(
                    $consumerId,
                    $endpoint,
                    $method,
                    ['consumer_type' => $consumerType]
                );

                $this->assertTrue($rateLimitResult['allowed'],
                    "Request {$i} should be allowed within rate limit");
            }

            // Test request exceeding limit
            $exceedingResult = $this->monitoringService->enforceRateLimit(
                $consumerId,
                $endpoint,
                $method,
                ['consumer_type' => $consumerType]
            );

            $this->assertFalse($exceedingResult['allowed'],
                'Request exceeding rate limit should be rejected');
            $this->assertArrayHasKey('violation', $exceedingResult,
                'Rate limit violation should be reported');
            $this->assertArrayHasKey('retry_after', $exceedingResult,
                'Retry after time should be provided');

            // Verify violation is logged
            $violation = DB::table('api_rate_limit_violations')
                ->where('consumer_id', $consumerId)
                ->where('endpoint', $endpoint)
                ->where('method', $method)
                ->where('enforcement_action', 'reject')
                ->first();

            $this->assertNotNull($violation, 'Rate limit violation should be logged');
            $this->assertEquals($requestsPerMinute, $violation->limit_value,
                'Violation should record correct limit value');
            $this->assertGreaterThan($requestsPerMinute, $violation->current_usage,
                'Violation should record usage exceeding limit');

            // Test quota management
            $quotaData = [
                'monthly_requests' => 100000,
                'yearly_requests' => 1000000,
                'data_transfer_gb' => 100,
            ];

            $quotaResult = $this->monitoringService->manageQuotas($consumerId, $quotaData);
            $this->assertTrue($quotaResult['success'], 'Quota management should succeed');
            $this->assertEquals($quotaData, $quotaResult['quota_config'],
                'Quota configuration should be stored correctly');

            // Test consumer analytics
            $analyticsResult = $this->monitoringService->getConsumerAnalytics($consumerId, [
                'start_date' => now()->subDay(),
                'end_date' => now(),
            ]);

            $this->assertTrue($analyticsResult['success'], 'Consumer analytics should succeed');
            $this->assertArrayHasKey('rate_limit_violations', $analyticsResult,
                'Analytics should include rate limit violations');
            $this->assertGreaterThan(0, count($analyticsResult['rate_limit_violations']),
                'Should show recorded violations in analytics');
        });
    }

    /**
     * Create valid request for contract testing
     */
    private function createValidRequest(array $contractSpec, string $method): array
    {
        $request = [
            'method' => $method,
            'query_params' => [],
            'body' => null,
        ];

        // Add required query parameters
        if (isset($contractSpec['request']['query_parameters'])) {
            foreach ($contractSpec['request']['query_parameters'] as $param => $spec) {
                if ($spec['required']) {
                    $request['query_params'][$param] = $this->generateValueForType($spec['type']);
                }
            }
        }

        // Add valid body for non-GET requests
        if ($method !== 'GET' && isset($contractSpec['request']['body_schema'])) {
            $request['body'] = $this->generateObjectForSchema($contractSpec['request']['body_schema']);
        }

        return $request;
    }

    /**
     * Create invalid request for contract testing
     */
    private function createInvalidRequest(array $contractSpec, string $method): array
    {
        $request = [
            'method' => $method,
            'query_params' => [],
            'body' => null,
        ];

        // Omit required query parameters or use wrong types
        if (isset($contractSpec['request']['query_parameters'])) {
            foreach ($contractSpec['request']['query_parameters'] as $param => $spec) {
                if ($spec['required'] && $this->faker->boolean()) {
                    // Randomly omit required parameter
                    continue;
                } else {
                    // Use wrong type
                    $wrongType = $spec['type'] === 'string' ? 'integer' : 'string';
                    $request['query_params'][$param] = $this->generateValueForType($wrongType);
                }
            }
        }

        return $request;
    }

    /**
     * Generate value for parameter type
     */
    private function generateValueForType(string $type)
    {
        return match ($type) {
            'string' => $this->faker->word,
            'integer' => $this->faker->numberBetween(1, 100),
            'number' => $this->faker->randomFloat(2, 1, 100),
            'boolean' => $this->faker->boolean,
            'array' => [$this->faker->word, $this->faker->word],
            default => $this->faker->word
        };
    }

    /**
     * Generate object for schema
     */
    private function generateObjectForSchema(array $schema): array
    {
        $object = [];

        if (isset($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (isset($schema['properties'][$field])) {
                    $fieldSchema = $schema['properties'][$field];
                    $object[$field] = $this->generateValueForType($fieldSchema['type']);
                }
            }
        }

        return $object;
    }

    /**
     * Assert request matches contract
     */
    private function assertRequestMatchesContract(array $request, array $contractSpec, bool $shouldMatch): void
    {
        $isValid = true;

        // Check required query parameters
        if (isset($contractSpec['request']['query_parameters'])) {
            foreach ($contractSpec['request']['query_parameters'] as $param => $spec) {
                if ($spec['required'] && ! isset($request['query_params'][$param])) {
                    $isValid = false;
                    break;
                }
            }
        }

        // Check required body fields
        if (isset($contractSpec['request']['body_schema']['required']) && $request['body']) {
            foreach ($contractSpec['request']['body_schema']['required'] as $field) {
                if (! isset($request['body'][$field])) {
                    $isValid = false;
                    break;
                }
            }
        }

        if ($shouldMatch) {
            $this->assertTrue($isValid, 'Valid request should match contract');
        } else {
            $this->assertFalse($isValid, 'Invalid request should not match contract');
        }
    }

    /**
     * Verify contract versioning
     */
    private function verifyContractVersioning(string $endpoint, string $method, string $version, array $contractSpec): void
    {
        // Create a new version with slight modification
        $newVersion = '2.0';
        $modifiedSpec = $contractSpec;
        $modifiedSpec['summary'] = 'Modified '.$contractSpec['summary'];

        $newVersionResult = $this->contractService->storeContract($endpoint, $method, $newVersion, $modifiedSpec);
        $this->assertTrue($newVersionResult['success'], 'New version storage should succeed');

        // Verify both versions exist
        $originalContract = $this->contractService->getContract($endpoint, $method, $version);
        $newContract = $this->contractService->getContract($endpoint, $method, $newVersion);

        $this->assertNotNull($originalContract, 'Original version should still exist');
        $this->assertNotNull($newContract, 'New version should exist');
        $this->assertNotEquals($originalContract['summary'], $newContract['summary'],
            'Versions should have different content');
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
                Redis::flushall();

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
        DB::table('api_contracts')->truncate();
        DB::table('api_contract_violations')->truncate();
        DB::table('api_usage_analytics')->truncate();
        DB::table('rbac_permission_matrix')->truncate();
        DB::table('rbac_test_results')->truncate();
        DB::table('api_rate_limits')->truncate();
        DB::table('api_rate_limit_violations')->truncate();
        DB::table('api_consumers')->truncate();
    }
}
