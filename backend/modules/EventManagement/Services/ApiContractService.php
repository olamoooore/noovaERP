<?php

namespace Modules\EventManagement\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Exception;

/**
 * ApiContractService - Task 12.1 & 12.2
 * 
 * Service for API contract management, validation, versioning, and monitoring.
 * Handles contract storage, retrieval, validation, and violation tracking.
 * 
 * Requirements: 28.1, 28.2, 28.3
 */
class ApiContractService
{
    private const CACHE_PREFIX = 'api_contract:';
    private const CONTRACT_STORAGE_PATH = 'api_contracts';
    private const VIOLATION_RETENTION_DAYS = 30;

    /**
     * Get API contract for endpoint
     * 
     * @param string $endpoint Normalized endpoint path
     * @param string $method HTTP method
     * @param string $version API version
     * @return array|null Contract specification
     */
    public function getContract(string $endpoint, string $method, string $version): ?array
    {
        try {
            // Try to load from database first
            $contract = DB::table('api_contracts')
                ->where('endpoint', $endpoint)
                ->where('method', $method)
                ->where('version', $version)
                ->where('is_active', true)
                ->first();

            if ($contract) {
                return json_decode($contract->contract_spec, true);
            }

            // Fallback to file-based contracts
            return $this->loadContractFromFile($endpoint, $method, $version);

        } catch (Exception $e) {
            Log::error('Failed to load API contract', [
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Store API contract
     * 
     * @param string $endpoint Endpoint path
     * @param string $method HTTP method
     * @param string $version API version
     * @param array $contractSpec Contract specification
     * @return array Storage result
     */
    public function storeContract(string $endpoint, string $method, string $version, array $contractSpec): array
    {
        try {
            DB::beginTransaction();

            // Deactivate existing contracts for this endpoint/method/version
            DB::table('api_contracts')
                ->where('endpoint', $endpoint)
                ->where('method', $method)
                ->where('version', $version)
                ->update(['is_active' => false, 'updated_at' => now()]);

            // Insert new contract
            $contractId = DB::table('api_contracts')->insertGetId([
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'contract_spec' => json_encode($contractSpec),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();

            // Clear cache
            $this->clearContractCache($endpoint, $method, $version);

            Log::info('API contract stored', [
                'contract_id' => $contractId,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version
            ]);

            return [
                'success' => true,
                'contract_id' => $contractId,
                'message' => 'Contract stored successfully'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to store API contract', [
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to store contract',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Record contract violation
     * 
     * @param Request $request HTTP request
     * @param string $violationType Type of violation
     * @param array $details Violation details
     * @return void
     */
    public function recordViolation(Request $request, string $violationType, array $details): void
    {
        try {
            DB::table('api_contract_violations')->insert([
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'violation_type' => $violationType,
                'violation_details' => json_encode($details),
                'request_id' => $request->header('X-Request-ID'),
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'tenant_id' => $this->extractTenantId($request),
                'user_id' => $request->user()?->id,
                'occurred_at' => now(),
                'created_at' => now()
            ]);

            // Update violation metrics
            $this->updateViolationMetrics($violationType);

        } catch (Exception $e) {
            Log::error('Failed to record contract violation', [
                'violation_type' => $violationType,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Record contract compliance
     * 
     * @param Request $request HTTP request
     * @param array $contract Contract that was validated
     * @return void
     */
    public function recordCompliance(Request $request, array $contract): void
    {
        try {
            // Update compliance metrics (lightweight)
            $cacheKey = self::CACHE_PREFIX . 'compliance:' . date('Y-m-d-H');
            Cache::increment($cacheKey, 1);
            Cache::expire($cacheKey, 3600); // 1 hour TTL

        } catch (Exception $e) {
            Log::error('Failed to record contract compliance', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get contract violations summary
     * 
     * @param array $options Query options
     * @return array Violations summary
     */
    public function getViolationsSummary(array $options = []): array
    {
        try {
            $startDate = $options['start_date'] ?? now()->subDays(7);
            $endDate = $options['end_date'] ?? now();
            $tenantId = $options['tenant_id'] ?? null;

            $query = DB::table('api_contract_violations')
                ->whereBetween('occurred_at', [$startDate, $endDate]);

            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            // Overall statistics
            $totalViolations = $query->count();
            
            // Violations by type
            $violationsByType = $query->selectRaw('violation_type, COUNT(*) as count')
                ->groupBy('violation_type')
                ->get()
                ->pluck('count', 'violation_type')
                ->toArray();

            // Violations by endpoint
            $violationsByEndpoint = $query->selectRaw('endpoint, method, COUNT(*) as count')
                ->groupBy(['endpoint', 'method'])
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
                ->toArray();

            // Violations over time (daily)
            $violationsOverTime = $query->selectRaw('DATE(occurred_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->pluck('count', 'date')
                ->toArray();

            return [
                'success' => true,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_violations' => $totalViolations,
                    'violations_by_type' => $violationsByType,
                    'violations_by_endpoint' => $violationsByEndpoint,
                    'violations_over_time' => $violationsOverTime
                ]
            ];

        } catch (Exception $e) {
            Log::error('Failed to get violations summary', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get violations summary',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Check contract compatibility between versions
     * 
     * @param string $endpoint Endpoint path
     * @param string $method HTTP method
     * @param string $oldVersion Old version
     * @param string $newVersion New version
     * @return array Compatibility check result
     */
    public function checkCompatibility(string $endpoint, string $method, string $oldVersion, string $newVersion): array
    {
        try {
            $oldContract = $this->getContract($endpoint, $method, $oldVersion);
            $newContract = $this->getContract($endpoint, $method, $newVersion);

            if (!$oldContract || !$newContract) {
                return [
                    'compatible' => false,
                    'reason' => 'One or both contracts not found'
                ];
            }

            $issues = [];

            // Check request compatibility
            $requestIssues = $this->checkRequestCompatibility($oldContract, $newContract);
            if (!empty($requestIssues)) {
                $issues['request'] = $requestIssues;
            }

            // Check response compatibility
            $responseIssues = $this->checkResponseCompatibility($oldContract, $newContract);
            if (!empty($responseIssues)) {
                $issues['response'] = $responseIssues;
            }

            $compatible = empty($issues);

            return [
                'compatible' => $compatible,
                'issues' => $issues,
                'compatibility_score' => $this->calculateCompatibilityScore($issues)
            ];

        } catch (Exception $e) {
            Log::error('Failed to check contract compatibility', [
                'endpoint' => $endpoint,
                'method' => $method,
                'old_version' => $oldVersion,
                'new_version' => $newVersion,
                'error' => $e->getMessage()
            ]);

            return [
                'compatible' => false,
                'error' => 'Compatibility check failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate OpenAPI specification for contracts
     * 
     * @param string $version API version
     * @param array $options Generation options
     * @return array OpenAPI specification
     */
    public function generateOpenApiSpec(string $version, array $options = []): array
    {
        try {
            $contracts = DB::table('api_contracts')
                ->where('version', $version)
                ->where('is_active', true)
                ->get();

            $openApiSpec = [
                'openapi' => '3.0.3',
                'info' => [
                    'title' => 'ERP Event Management API',
                    'version' => $version,
                    'description' => 'API for ERP Event Management Module'
                ],
                'servers' => [
                    [
                        'url' => config('app.url') . "/api/v{$version}",
                        'description' => 'Production server'
                    ]
                ],
                'paths' => []
            ];

            foreach ($contracts as $contract) {
                $contractSpec = json_decode($contract->contract_spec, true);
                $path = $contract->endpoint;
                $method = strtolower($contract->method);

                if (!isset($openApiSpec['paths'][$path])) {
                    $openApiSpec['paths'][$path] = [];
                }

                $openApiSpec['paths'][$path][$method] = $this->convertToOpenApiOperation($contractSpec);
            }

            return [
                'success' => true,
                'specification' => $openApiSpec
            ];

        } catch (Exception $e) {
            Log::error('Failed to generate OpenAPI specification', [
                'version' => $version,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to generate OpenAPI spec',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Load contract from file
     */
    private function loadContractFromFile(string $endpoint, string $method, string $version): ?array
    {
        $filename = $this->getContractFilename($endpoint, $method, $version);
        $path = self::CONTRACT_STORAGE_PATH . '/' . $filename;

        if (Storage::exists($path)) {
            $content = Storage::get($path);
            return json_decode($content, true);
        }

        return null;
    }

    /**
     * Get contract filename
     */
    private function getContractFilename(string $endpoint, string $method, string $version): string
    {
        $sanitizedEndpoint = str_replace(['/', '{', '}'], ['_', '', ''], $endpoint);
        return "{$version}_{$method}_{$sanitizedEndpoint}.json";
    }

    /**
     * Clear contract cache
     */
    private function clearContractCache(string $endpoint, string $method, string $version): void
    {
        $cacheKey = self::CACHE_PREFIX . "contract:{$version}:{$method}:{$endpoint}";
        Cache::forget($cacheKey);
    }

    /**
     * Extract tenant ID from request
     */
    private function extractTenantId(Request $request): ?string
    {
        // Try header first
        $tenantId = $request->header('X-Tenant-ID');
        if ($tenantId) {
            return $tenantId;
        }

        // Try from authenticated user
        $user = $request->user();
        if ($user && isset($user->tenant_id)) {
            return $user->tenant_id;
        }

        return null;
    }

    /**
     * Update violation metrics
     */
    private function updateViolationMetrics(string $violationType): void
    {
        $cacheKey = self::CACHE_PREFIX . "violations:{$violationType}:" . date('Y-m-d-H');
        Cache::increment($cacheKey, 1);
        Cache::expire($cacheKey, 3600); // 1 hour TTL
    }

    /**
     * Check request compatibility
     */
    private function checkRequestCompatibility(array $oldContract, array $newContract): array
    {
        $issues = [];

        // Check if new required parameters were added
        $oldRequired = $oldContract['request']['required'] ?? [];
        $newRequired = $newContract['request']['required'] ?? [];
        
        $addedRequired = array_diff($newRequired, $oldRequired);
        if (!empty($addedRequired)) {
            $issues[] = 'Added required parameters: ' . implode(', ', $addedRequired);
        }

        // Check if parameter types changed
        $oldParams = $oldContract['request']['query_parameters'] ?? [];
        $newParams = $newContract['request']['query_parameters'] ?? [];

        foreach ($oldParams as $param => $oldSpec) {
            if (isset($newParams[$param])) {
                $newSpec = $newParams[$param];
                if (($oldSpec['type'] ?? null) !== ($newSpec['type'] ?? null)) {
                    $issues[] = "Parameter type changed for {$param}";
                }
            }
        }

        return $issues;
    }

    /**
     * Check response compatibility
     */
    private function checkResponseCompatibility(array $oldContract, array $newContract): array
    {
        $issues = [];

        $oldResponses = $oldContract['responses'] ?? [];
        $newResponses = $newContract['responses'] ?? [];

        // Check if response status codes were removed
        $removedCodes = array_diff(array_keys($oldResponses), array_keys($newResponses));
        if (!empty($removedCodes)) {
            $issues[] = 'Removed response codes: ' . implode(', ', $removedCodes);
        }

        return $issues;
    }

    /**
     * Calculate compatibility score
     */
    private function calculateCompatibilityScore(array $issues): float
    {
        if (empty($issues)) {
            return 100.0;
        }

        $totalIssues = 0;
        foreach ($issues as $category => $categoryIssues) {
            $totalIssues += count($categoryIssues);
        }

        // Simple scoring: each issue reduces score by 10 points
        return max(0, 100 - ($totalIssues * 10));
    }

    /**
     * Convert contract to OpenAPI operation
     */
    private function convertToOpenApiOperation(array $contractSpec): array
    {
        $operation = [
            'summary' => $contractSpec['summary'] ?? 'API Operation',
            'description' => $contractSpec['description'] ?? '',
            'parameters' => [],
            'responses' => []
        ];

        // Convert query parameters
        if (isset($contractSpec['request']['query_parameters'])) {
            foreach ($contractSpec['request']['query_parameters'] as $param => $spec) {
                $operation['parameters'][] = [
                    'name' => $param,
                    'in' => 'query',
                    'required' => $spec['required'] ?? false,
                    'schema' => [
                        'type' => $spec['type'] ?? 'string'
                    ]
                ];
            }
        }

        // Convert responses
        if (isset($contractSpec['responses'])) {
            foreach ($contractSpec['responses'] as $code => $response) {
                $operation['responses'][$code] = [
                    'description' => $response['description'] ?? 'Response',
                    'content' => [
                        'application/json' => [
                            'schema' => $response['body_schema'] ?? ['type' => 'object']
                        ]
                    ]
                ];
            }
        }

        return $operation;
    }
}