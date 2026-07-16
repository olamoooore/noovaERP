<?php

namespace Modules\EventManagement\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RbacMatrixService - Task 12.3
 *
 * Service for automated RBAC permission matrix testing, role-based access validation,
 * permission regression testing, and RBAC audit and compliance reporting.
 *
 * Requirements: 28.4
 */
class RbacMatrixService
{
    private const CACHE_PREFIX = 'rbac_matrix:';

    private const TEST_CACHE_TTL = 300; // 5 minutes

    private const PERMISSION_CACHE_TTL = 600; // 10 minutes

    /**
     * Run automated RBAC permission matrix testing
     *
     * @param  array  $options  Testing options
     * @return array Testing result
     */
    public function runPermissionMatrixTests(array $options = []): array
    {
        try {
            $testSuiteId = $options['test_suite_id'] ?? 'auto_'.uniqid();
            $roles = $options['roles'] ?? $this->getAllActiveRoles();
            $endpoints = $options['endpoints'] ?? $this->getAllActiveEndpoints();
            $version = $options['version'] ?? '1.0';

            $testResults = [];
            $totalTests = 0;
            $passedTests = 0;
            $failedTests = 0;

            foreach ($roles as $role) {
                foreach ($endpoints as $endpoint) {
                    foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
                        $testResult = $this->testRoleEndpointPermission(
                            $testSuiteId,
                            $role,
                            $endpoint,
                            $method,
                            $version
                        );

                        $testResults[] = $testResult;
                        $totalTests++;

                        if ($testResult['test_passed']) {
                            $passedTests++;
                        } else {
                            $failedTests++;
                        }
                    }
                }
            }

            // Store test suite summary
            $this->storeTestSuiteSummary($testSuiteId, [
                'total_tests' => $totalTests,
                'passed_tests' => $passedTests,
                'failed_tests' => $failedTests,
                'success_rate' => $totalTests > 0 ? ($passedTests / $totalTests) * 100 : 0,
                'roles_tested' => count($roles),
                'endpoints_tested' => count($endpoints),
                'version' => $version,
            ]);

            Log::info('RBAC permission matrix tests completed', [
                'test_suite_id' => $testSuiteId,
                'total_tests' => $totalTests,
                'passed_tests' => $passedTests,
                'failed_tests' => $failedTests,
                'success_rate' => $totalTests > 0 ? ($passedTests / $totalTests) * 100 : 0,
            ]);

            return [
                'success' => true,
                'test_suite_id' => $testSuiteId,
                'summary' => [
                    'total_tests' => $totalTests,
                    'passed_tests' => $passedTests,
                    'failed_tests' => $failedTests,
                    'success_rate' => $totalTests > 0 ? ($passedTests / $totalTests) * 100 : 0,
                ],
                'test_results' => $testResults,
                'completed_at' => now(),
            ];

        } catch (Exception $e) {
            Log::error('Failed to run RBAC permission matrix tests', [
                'options' => $options,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to run permission tests',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate role-based access for specific request
     *
     * @param  string  $role  User role
     * @param  string  $endpoint  API endpoint
     * @param  string  $method  HTTP method
     * @param  string  $version  API version
     * @param  array  $context  Additional context
     * @return array Validation result
     */
    public function validateRoleAccess(string $role, string $endpoint, string $method, string $version, array $context = []): array
    {
        try {
            $cacheKey = self::CACHE_PREFIX."access:{$role}:{$endpoint}:{$method}:{$version}";

            $permission = Cache::remember($cacheKey, self::PERMISSION_CACHE_TTL, function () use ($role, $endpoint, $method, $version) {
                return $this->getPermissionFromMatrix($role, $endpoint, $method, $version);
            });

            if (! $permission) {
                return [
                    'allowed' => false,
                    'reason' => 'No permission defined for this role and endpoint',
                    'permission_level' => 'deny',
                ];
            }

            // Check if permission is active and not expired
            if (! $permission->is_active) {
                return [
                    'allowed' => false,
                    'reason' => 'Permission is inactive',
                    'permission_level' => 'deny',
                ];
            }

            if ($permission->expires_at && Carbon::parse($permission->expires_at)->isPast()) {
                return [
                    'allowed' => false,
                    'reason' => 'Permission has expired',
                    'permission_level' => 'deny',
                ];
            }

            // Evaluate permission level
            switch ($permission->permission_level) {
                case 'allow':
                    return [
                        'allowed' => true,
                        'reason' => 'Explicit allow permission',
                        'permission_level' => 'allow',
                        'field_permissions' => json_decode($permission->field_permissions, true),
                    ];

                case 'deny':
                    return [
                        'allowed' => false,
                        'reason' => 'Explicit deny permission',
                        'permission_level' => 'deny',
                    ];

                case 'conditional':
                    return $this->evaluateConditionalPermission($permission, $context);

                default:
                    return [
                        'allowed' => false,
                        'reason' => 'Unknown permission level',
                        'permission_level' => 'deny',
                    ];
            }

        } catch (Exception $e) {
            Log::error('Failed to validate role access', [
                'role' => $role,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            return [
                'allowed' => false,
                'reason' => 'Access validation error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Run permission regression testing
     *
     * @param  array  $options  Regression testing options
     * @return array Regression test result
     */
    public function runRegressionTests(array $options = []): array
    {
        try {
            $baselineTestSuite = $options['baseline_test_suite'] ?? null;
            $currentTestSuite = $options['current_test_suite'] ?? 'regression_'.uniqid();

            if (! $baselineTestSuite) {
                // Get the most recent successful test suite as baseline
                $baselineTestSuite = $this->getMostRecentSuccessfulTestSuite();
            }

            if (! $baselineTestSuite) {
                return [
                    'success' => false,
                    'error' => 'No baseline test suite available for regression testing',
                ];
            }

            // Run current tests
            $currentResults = $this->runPermissionMatrixTests([
                'test_suite_id' => $currentTestSuite,
            ]);

            if (! $currentResults['success']) {
                return $currentResults;
            }

            // Compare with baseline
            $regressionAnalysis = $this->compareTestResults($baselineTestSuite, $currentTestSuite);

            return [
                'success' => true,
                'baseline_test_suite' => $baselineTestSuite,
                'current_test_suite' => $currentTestSuite,
                'regression_analysis' => $regressionAnalysis,
                'current_results' => $currentResults,
                'completed_at' => now(),
            ];

        } catch (Exception $e) {
            Log::error('Failed to run regression tests', [
                'options' => $options,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to run regression tests',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate RBAC audit and compliance report
     *
     * @param  array  $options  Report options
     * @return array Audit report
     */
    public function generateAuditReport(array $options = []): array
    {
        try {
            $startDate = $options['start_date'] ?? now()->subDays(30);
            $endDate = $options['end_date'] ?? now();
            $includeDetails = $options['include_details'] ?? false;

            // Permission matrix coverage
            $matrixCoverage = $this->analyzePermissionMatrixCoverage();

            // Test execution history
            $testHistory = $this->getTestExecutionHistory($startDate, $endDate);

            // Permission violations and failures
            $violations = $this->getPermissionViolations($startDate, $endDate);

            // Role and endpoint analysis
            $roleAnalysis = $this->analyzeRolePermissions();
            $endpointAnalysis = $this->analyzeEndpointPermissions();

            // Compliance metrics
            $complianceMetrics = $this->calculateComplianceMetrics($startDate, $endDate);

            $report = [
                'success' => true,
                'report_period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'matrix_coverage' => $matrixCoverage,
                'test_execution_summary' => $testHistory,
                'compliance_metrics' => $complianceMetrics,
                'role_analysis' => $roleAnalysis,
                'endpoint_analysis' => $endpointAnalysis,
                'violations_summary' => $violations,
                'generated_at' => now(),
            ];

            if ($includeDetails) {
                $report['detailed_violations'] = $this->getDetailedViolations($startDate, $endDate);
                $report['permission_matrix_details'] = $this->getPermissionMatrixDetails();
            }

            return $report;

        } catch (Exception $e) {
            Log::error('Failed to generate RBAC audit report', [
                'options' => $options,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to generate audit report',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update permission matrix entry
     *
     * @param  array  $permissionData  Permission configuration
     * @return array Update result
     */
    public function updatePermissionMatrix(array $permissionData): array
    {
        try {
            DB::beginTransaction();

            // Validate permission data
            $this->validatePermissionData($permissionData);

            $role = $permissionData['role_name'];
            $endpoint = $permissionData['endpoint'];
            $method = $permissionData['method'];
            $version = $permissionData['version'];

            // Deactivate existing permission
            DB::table('rbac_permission_matrix')
                ->where('role_name', $role)
                ->where('endpoint', $endpoint)
                ->where('method', $method)
                ->where('version', $version)
                ->update(['is_active' => false, 'updated_at' => now()]);

            // Insert new permission
            $permissionId = DB::table('rbac_permission_matrix')->insertGetId([
                'role_name' => $role,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'permission_level' => $permissionData['permission_level'],
                'conditions' => json_encode($permissionData['conditions'] ?? []),
                'field_permissions' => json_encode($permissionData['field_permissions'] ?? []),
                'is_active' => true,
                'permission_source' => $permissionData['source'] ?? 'manual',
                'created_by_staff_id' => $permissionData['created_by'] ?? null,
                'effective_from' => $permissionData['effective_from'] ?? now(),
                'expires_at' => $permissionData['expires_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            // Clear cache
            $this->clearPermissionCache($role, $endpoint, $method, $version);

            Log::info('Permission matrix updated', [
                'permission_id' => $permissionId,
                'role' => $role,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'permission_level' => $permissionData['permission_level'],
            ]);

            return [
                'success' => true,
                'permission_id' => $permissionId,
                'message' => 'Permission matrix updated successfully',
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update permission matrix', [
                'permission_data' => $permissionData,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to update permission matrix',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test role-endpoint permission
     */
    private function testRoleEndpointPermission(string $testSuiteId, string $role, string $endpoint, string $method, string $version): array
    {
        $startTime = microtime(true);

        try {
            // Get expected result from permission matrix
            $permission = $this->getPermissionFromMatrix($role, $endpoint, $method, $version);
            $expectedResult = $permission ? ($permission->permission_level === 'deny' ? 'deny' : 'allow') : 'deny';

            // Perform actual access validation
            $validationResult = $this->validateRoleAccess($role, $endpoint, $method, $version);
            $actualResult = $validationResult['allowed'] ? 'allow' : 'deny';

            $testPassed = ($expectedResult === $actualResult);
            $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

            // Store test result
            DB::table('rbac_test_results')->insert([
                'test_suite_id' => $testSuiteId,
                'role_name' => $role,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'expected_result' => $expectedResult,
                'actual_result' => $actualResult,
                'test_passed' => $testPassed,
                'test_context' => json_encode([
                    'validation_result' => $validationResult,
                    'permission_exists' => $permission !== null,
                ]),
                'failure_reason' => $testPassed ? null : "Expected {$expectedResult}, got {$actualResult}",
                'execution_time_ms' => $executionTime,
                'tested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'role' => $role,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'expected_result' => $expectedResult,
                'actual_result' => $actualResult,
                'test_passed' => $testPassed,
                'execution_time_ms' => $executionTime,
                'failure_reason' => $testPassed ? null : "Expected {$expectedResult}, got {$actualResult}",
            ];

        } catch (Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;

            // Store failed test result
            DB::table('rbac_test_results')->insert([
                'test_suite_id' => $testSuiteId,
                'role_name' => $role,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'expected_result' => 'unknown',
                'actual_result' => 'error',
                'test_passed' => false,
                'failure_reason' => 'Test execution error: '.$e->getMessage(),
                'execution_time_ms' => $executionTime,
                'tested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'role' => $role,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'expected_result' => 'unknown',
                'actual_result' => 'error',
                'test_passed' => false,
                'execution_time_ms' => $executionTime,
                'failure_reason' => 'Test execution error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get permission from matrix
     */
    private function getPermissionFromMatrix(string $role, string $endpoint, string $method, string $version): ?object
    {
        return DB::table('rbac_permission_matrix')
            ->where('role_name', $role)
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->where('version', $version)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Evaluate conditional permission
     */
    private function evaluateConditionalPermission(object $permission, array $context): array
    {
        $conditions = json_decode($permission->conditions, true) ?? [];

        if (empty($conditions)) {
            return [
                'allowed' => false,
                'reason' => 'Conditional permission has no conditions defined',
                'permission_level' => 'conditional',
            ];
        }

        foreach ($conditions as $condition) {
            if (! $this->evaluateCondition($condition, $context)) {
                return [
                    'allowed' => false,
                    'reason' => 'Conditional permission requirement not met',
                    'permission_level' => 'conditional',
                    'failed_condition' => $condition,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => 'All conditional permission requirements met',
            'permission_level' => 'conditional',
            'field_permissions' => json_decode($permission->field_permissions, true),
        ];
    }

    /**
     * Evaluate individual condition
     */
    private function evaluateCondition(array $condition, array $context): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;

        if (! $field || ! isset($context[$field])) {
            return false;
        }

        $contextValue = $context[$field];

        return match ($operator) {
            'equals' => $contextValue == $value,
            'not_equals' => $contextValue != $value,
            'greater_than' => $contextValue > $value,
            'less_than' => $contextValue < $value,
            'contains' => str_contains($contextValue, $value),
            'in' => in_array($contextValue, (array) $value),
            'not_in' => ! in_array($contextValue, (array) $value),
            default => false
        };
    }

    /**
     * Get all active roles
     */
    private function getAllActiveRoles(): array
    {
        return DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->distinct()
            ->pluck('role_name')
            ->toArray();
    }

    /**
     * Get all active endpoints
     */
    private function getAllActiveEndpoints(): array
    {
        return DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->distinct()
            ->pluck('endpoint')
            ->toArray();
    }

    /**
     * Store test suite summary
     */
    private function storeTestSuiteSummary(string $testSuiteId, array $summary): void
    {
        // Store in cache for quick access
        $cacheKey = self::CACHE_PREFIX."test_suite:{$testSuiteId}";
        Cache::put($cacheKey, $summary, self::TEST_CACHE_TTL);
    }

    /**
     * Get most recent successful test suite
     */
    private function getMostRecentSuccessfulTestSuite(): ?string
    {
        $recentTest = DB::table('rbac_test_results')
            ->selectRaw('test_suite_id, COUNT(*) as total_tests, SUM(CASE WHEN test_passed THEN 1 ELSE 0 END) as passed_tests')
            ->groupBy('test_suite_id')
            ->havingRaw('passed_tests = total_tests')
            ->orderBy('tested_at', 'desc')
            ->first();

        return $recentTest?->test_suite_id;
    }

    /**
     * Compare test results for regression analysis
     */
    private function compareTestResults(string $baselineTestSuite, string $currentTestSuite): array
    {
        $baselineResults = DB::table('rbac_test_results')
            ->where('test_suite_id', $baselineTestSuite)
            ->get()
            ->keyBy(function ($item) {
                return "{$item->role_name}:{$item->endpoint}:{$item->method}:{$item->version}";
            });

        $currentResults = DB::table('rbac_test_results')
            ->where('test_suite_id', $currentTestSuite)
            ->get()
            ->keyBy(function ($item) {
                return "{$item->role_name}:{$item->endpoint}:{$item->method}:{$item->version}";
            });

        $regressions = [];
        $improvements = [];
        $newTests = [];
        $removedTests = [];

        foreach ($currentResults as $key => $currentResult) {
            if (! isset($baselineResults[$key])) {
                $newTests[] = $key;

                continue;
            }

            $baselineResult = $baselineResults[$key];

            if ($baselineResult->test_passed && ! $currentResult->test_passed) {
                $regressions[] = [
                    'test_key' => $key,
                    'baseline_result' => $baselineResult->actual_result,
                    'current_result' => $currentResult->actual_result,
                    'failure_reason' => $currentResult->failure_reason,
                ];
            } elseif (! $baselineResult->test_passed && $currentResult->test_passed) {
                $improvements[] = [
                    'test_key' => $key,
                    'baseline_result' => $baselineResult->actual_result,
                    'current_result' => $currentResult->actual_result,
                ];
            }
        }

        foreach ($baselineResults as $key => $baselineResult) {
            if (! isset($currentResults[$key])) {
                $removedTests[] = $key;
            }
        }

        return [
            'regressions' => $regressions,
            'improvements' => $improvements,
            'new_tests' => $newTests,
            'removed_tests' => $removedTests,
            'regression_count' => count($regressions),
            'improvement_count' => count($improvements),
            'has_regressions' => ! empty($regressions),
        ];
    }

    /**
     * Analyze permission matrix coverage
     */
    private function analyzePermissionMatrixCoverage(): array
    {
        $totalPermissions = DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->count();

        $rolesWithPermissions = DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->distinct()
            ->count('role_name');

        $endpointsWithPermissions = DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->distinct()
            ->count('endpoint');

        $permissionsByLevel = DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->selectRaw('permission_level, COUNT(*) as count')
            ->groupBy('permission_level')
            ->pluck('count', 'permission_level')
            ->toArray();

        return [
            'total_permissions' => $totalPermissions,
            'roles_with_permissions' => $rolesWithPermissions,
            'endpoints_with_permissions' => $endpointsWithPermissions,
            'permissions_by_level' => $permissionsByLevel,
        ];
    }

    /**
     * Get test execution history
     */
    private function getTestExecutionHistory(Carbon $startDate, Carbon $endDate): array
    {
        $testSuites = DB::table('rbac_test_results')
            ->whereBetween('tested_at', [$startDate, $endDate])
            ->selectRaw('
                test_suite_id,
                COUNT(*) as total_tests,
                SUM(CASE WHEN test_passed THEN 1 ELSE 0 END) as passed_tests,
                AVG(execution_time_ms) as avg_execution_time,
                MIN(tested_at) as started_at,
                MAX(tested_at) as completed_at
            ')
            ->groupBy('test_suite_id')
            ->orderBy('started_at', 'desc')
            ->get();

        return $testSuites->toArray();
    }

    /**
     * Get permission violations
     */
    private function getPermissionViolations(Carbon $startDate, Carbon $endDate): array
    {
        $violations = DB::table('rbac_test_results')
            ->whereBetween('tested_at', [$startDate, $endDate])
            ->where('test_passed', false)
            ->selectRaw('
                role_name,
                endpoint,
                method,
                COUNT(*) as violation_count,
                MAX(tested_at) as last_violation
            ')
            ->groupBy(['role_name', 'endpoint', 'method'])
            ->orderBy('violation_count', 'desc')
            ->get();

        return $violations->toArray();
    }

    /**
     * Analyze role permissions
     */
    private function analyzeRolePermissions(): array
    {
        $roleStats = DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->selectRaw('
                role_name,
                COUNT(*) as total_permissions,
                SUM(CASE WHEN permission_level = "allow" THEN 1 ELSE 0 END) as allow_permissions,
                SUM(CASE WHEN permission_level = "deny" THEN 1 ELSE 0 END) as deny_permissions,
                SUM(CASE WHEN permission_level = "conditional" THEN 1 ELSE 0 END) as conditional_permissions
            ')
            ->groupBy('role_name')
            ->orderBy('total_permissions', 'desc')
            ->get();

        return $roleStats->toArray();
    }

    /**
     * Analyze endpoint permissions
     */
    private function analyzeEndpointPermissions(): array
    {
        $endpointStats = DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->selectRaw('
                endpoint,
                method,
                COUNT(*) as roles_with_access,
                SUM(CASE WHEN permission_level = "allow" THEN 1 ELSE 0 END) as allow_count,
                SUM(CASE WHEN permission_level = "deny" THEN 1 ELSE 0 END) as deny_count,
                SUM(CASE WHEN permission_level = "conditional" THEN 1 ELSE 0 END) as conditional_count
            ')
            ->groupBy(['endpoint', 'method'])
            ->orderBy('roles_with_access', 'desc')
            ->get();

        return $endpointStats->toArray();
    }

    /**
     * Calculate compliance metrics
     */
    private function calculateComplianceMetrics(Carbon $startDate, Carbon $endDate): array
    {
        $totalTests = DB::table('rbac_test_results')
            ->whereBetween('tested_at', [$startDate, $endDate])
            ->count();

        $passedTests = DB::table('rbac_test_results')
            ->whereBetween('tested_at', [$startDate, $endDate])
            ->where('test_passed', true)
            ->count();

        $complianceRate = $totalTests > 0 ? ($passedTests / $totalTests) * 100 : 0;

        return [
            'total_tests' => $totalTests,
            'passed_tests' => $passedTests,
            'failed_tests' => $totalTests - $passedTests,
            'compliance_rate' => round($complianceRate, 2),
            'compliance_status' => $this->getComplianceStatus($complianceRate),
        ];
    }

    /**
     * Get detailed violations
     */
    private function getDetailedViolations(Carbon $startDate, Carbon $endDate): array
    {
        return DB::table('rbac_test_results')
            ->whereBetween('tested_at', [$startDate, $endDate])
            ->where('test_passed', false)
            ->orderBy('tested_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get permission matrix details
     */
    private function getPermissionMatrixDetails(): array
    {
        return DB::table('rbac_permission_matrix')
            ->where('is_active', true)
            ->orderBy(['role_name', 'endpoint', 'method'])
            ->get()
            ->toArray();
    }

    /**
     * Validate permission data
     */
    private function validatePermissionData(array $permissionData): void
    {
        $required = ['role_name', 'endpoint', 'method', 'version', 'permission_level'];

        foreach ($required as $field) {
            if (! isset($permissionData[$field])) {
                throw new Exception("Required field {$field} is missing");
            }
        }

        $validLevels = ['allow', 'deny', 'conditional'];
        if (! in_array($permissionData['permission_level'], $validLevels)) {
            throw new Exception('Invalid permission level');
        }

        $validMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        if (! in_array($permissionData['method'], $validMethods)) {
            throw new Exception('Invalid HTTP method');
        }
    }

    /**
     * Clear permission cache
     */
    private function clearPermissionCache(string $role, string $endpoint, string $method, string $version): void
    {
        $cacheKey = self::CACHE_PREFIX."access:{$role}:{$endpoint}:{$method}:{$version}";
        Cache::forget($cacheKey);
    }

    /**
     * Get compliance status
     */
    private function getComplianceStatus(float $complianceRate): string
    {
        if ($complianceRate >= 95) {
            return 'excellent';
        }
        if ($complianceRate >= 90) {
            return 'good';
        }
        if ($complianceRate >= 80) {
            return 'acceptable';
        }
        if ($complianceRate >= 70) {
            return 'needs_improvement';
        }

        return 'critical';
    }
}
