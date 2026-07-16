<?php

namespace Modules\EventManagement\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ApiGovernanceService - Task 12.2
 *
 * Service for API governance tools including OpenAPI specification generation,
 * automated API documentation, usage analytics, deprecation tracking, and
 * API lifecycle management workflows.
 *
 * Requirements: 28.3, 28.5
 */
class ApiGovernanceService
{
    private const CACHE_PREFIX = 'api_governance:';

    private const DOCUMENTATION_PATH = 'api_documentation';

    private const SPEC_CACHE_TTL = 3600; // 1 hour

    /**
     * Generate comprehensive OpenAPI specifications for all endpoints
     *
     * @param  array  $options  Generation options
     * @return array Generation result
     */
    public function generateOpenApiSpecifications(array $options = []): array
    {
        try {
            $versions = $options['versions'] ?? $this->getActiveApiVersions();
            $includeDeprecated = $options['include_deprecated'] ?? false;

            $specifications = [];

            foreach ($versions as $version) {
                $spec = $this->generateVersionSpecification($version, $includeDeprecated);
                if ($spec['success']) {
                    $specifications[$version] = $spec['specification'];

                    // Store specification
                    $this->storeSpecification($version, $spec['specification']);
                }
            }

            // Generate combined specification
            $combinedSpec = $this->generateCombinedSpecification($specifications);

            return [
                'success' => true,
                'specifications' => $specifications,
                'combined_specification' => $combinedSpec,
                'generated_at' => now(),
                'versions_processed' => count($versions),
            ];

        } catch (Exception $e) {
            Log::error('Failed to generate OpenAPI specifications', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to generate specifications',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate automated API documentation
     *
     * @param  array  $options  Documentation options
     * @return array Documentation generation result
     */
    public function generateApiDocumentation(array $options = []): array
    {
        try {
            $version = $options['version'] ?? 'latest';
            $format = $options['format'] ?? 'html';
            $includeExamples = $options['include_examples'] ?? true;

            // Get OpenAPI specification
            $specResult = $this->generateVersionSpecification($version);
            if (! $specResult['success']) {
                return $specResult;
            }

            $specification = $specResult['specification'];

            // Generate documentation based on format
            $documentation = match ($format) {
                'html' => $this->generateHtmlDocumentation($specification, $includeExamples),
                'markdown' => $this->generateMarkdownDocumentation($specification, $includeExamples),
                'json' => $specification,
                default => throw new Exception("Unsupported documentation format: {$format}")
            };

            // Store documentation
            $filename = $this->storeDocumentation($version, $format, $documentation);

            // Generate usage analytics for documentation
            $usageAnalytics = $this->generateUsageAnalytics($version);

            return [
                'success' => true,
                'documentation' => $documentation,
                'filename' => $filename,
                'format' => $format,
                'version' => $version,
                'usage_analytics' => $usageAnalytics,
                'generated_at' => now(),
            ];

        } catch (Exception $e) {
            Log::error('Failed to generate API documentation', [
                'version' => $options['version'] ?? 'unknown',
                'format' => $options['format'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to generate documentation',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Track API usage analytics and patterns
     *
     * @param  array  $usageData  Usage data to track
     * @return array Tracking result
     */
    public function trackApiUsage(array $usageData): array
    {
        try {
            DB::beginTransaction();

            $today = now()->toDateString();

            // Update or create usage analytics record
            $existing = DB::table('api_usage_analytics')
                ->where('endpoint', $usageData['endpoint'])
                ->where('method', $usageData['method'])
                ->where('version', $usageData['version'])
                ->where('tenant_id', $usageData['tenant_id'] ?? null)
                ->where('consumer_id', $usageData['consumer_id'] ?? null)
                ->where('usage_date', $today)
                ->first();

            if ($existing) {
                // Update existing record
                DB::table('api_usage_analytics')
                    ->where('id', $existing->id)
                    ->update([
                        'request_count' => $existing->request_count + 1,
                        'avg_response_time_ms' => $this->calculateNewAverage(
                            $existing->avg_response_time_ms,
                            $existing->request_count,
                            $usageData['response_time_ms'] ?? 0
                        ),
                        'success_count' => $existing->success_count + ($usageData['success'] ? 1 : 0),
                        'error_count' => $existing->error_count + ($usageData['success'] ? 0 : 1),
                        'error_rate_percent' => $this->calculateErrorRate(
                            $existing->success_count + ($usageData['success'] ? 1 : 0),
                            $existing->error_count + ($usageData['success'] ? 0 : 1)
                        ),
                        'status_code_distribution' => $this->updateStatusCodeDistribution(
                            json_decode($existing->status_code_distribution, true) ?? [],
                            $usageData['status_code'] ?? 200
                        ),
                        'updated_at' => now(),
                    ]);
            } else {
                // Create new record
                DB::table('api_usage_analytics')->insert([
                    'endpoint' => $usageData['endpoint'],
                    'method' => $usageData['method'],
                    'version' => $usageData['version'],
                    'tenant_id' => $usageData['tenant_id'] ?? null,
                    'user_id' => $usageData['user_id'] ?? null,
                    'consumer_type' => $usageData['consumer_type'] ?? 'unknown',
                    'consumer_id' => $usageData['consumer_id'] ?? null,
                    'request_count' => 1,
                    'avg_response_time_ms' => $usageData['response_time_ms'] ?? 0,
                    'success_count' => $usageData['success'] ? 1 : 0,
                    'error_count' => $usageData['success'] ? 0 : 1,
                    'error_rate_percent' => $usageData['success'] ? 0 : 100,
                    'status_code_distribution' => json_encode([
                        (string) ($usageData['status_code'] ?? 200) => 1,
                    ]),
                    'usage_date' => $today,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Usage analytics updated successfully',
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to track API usage', [
                'usage_data' => $usageData,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to track usage',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Manage API deprecation lifecycle
     *
     * @param  array  $deprecationData  Deprecation configuration
     * @return array Deprecation management result
     */
    public function manageApiDeprecation(array $deprecationData): array
    {
        try {
            DB::beginTransaction();

            $endpoint = $deprecationData['endpoint'];
            $method = $deprecationData['method'];
            $version = $deprecationData['version'];

            // Check if deprecation already exists
            $existing = DB::table('api_deprecations')
                ->where('endpoint', $endpoint)
                ->where('method', $method)
                ->where('version', $version)
                ->first();

            if ($existing) {
                // Update existing deprecation
                DB::table('api_deprecations')
                    ->where('id', $existing->id)
                    ->update([
                        'deprecation_status' => $deprecationData['status'],
                        'announced_at' => $deprecationData['announced_at'] ?? $existing->announced_at,
                        'deprecated_at' => $deprecationData['deprecated_at'] ?? $existing->deprecated_at,
                        'sunset_at' => $deprecationData['sunset_at'] ?? $existing->sunset_at,
                        'replacement_endpoint' => $deprecationData['replacement_endpoint'] ?? $existing->replacement_endpoint,
                        'replacement_version' => $deprecationData['replacement_version'] ?? $existing->replacement_version,
                        'deprecation_reason' => $deprecationData['reason'] ?? $existing->deprecation_reason,
                        'migration_guide' => $deprecationData['migration_guide'] ?? $existing->migration_guide,
                        'breaking_change' => $deprecationData['breaking_change'] ?? $existing->breaking_change,
                        'updated_at' => now(),
                    ]);

                $deprecationId = $existing->id;
            } else {
                // Create new deprecation
                $deprecationId = DB::table('api_deprecations')->insertGetId([
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'version' => $version,
                    'deprecation_status' => $deprecationData['status'],
                    'announced_at' => $deprecationData['announced_at'] ?? now(),
                    'deprecated_at' => $deprecationData['deprecated_at'] ?? null,
                    'sunset_at' => $deprecationData['sunset_at'] ?? null,
                    'replacement_endpoint' => $deprecationData['replacement_endpoint'] ?? null,
                    'replacement_version' => $deprecationData['replacement_version'] ?? null,
                    'deprecation_reason' => $deprecationData['reason'] ?? null,
                    'migration_guide' => $deprecationData['migration_guide'] ?? null,
                    'breaking_change' => $deprecationData['breaking_change'] ?? false,
                    'created_by_staff_id' => $deprecationData['created_by'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Identify affected consumers
            $affectedConsumers = $this->identifyAffectedConsumers($endpoint, $method, $version);

            // Update deprecation with affected consumers
            DB::table('api_deprecations')
                ->where('id', $deprecationId)
                ->update([
                    'affected_consumers' => json_encode($affectedConsumers),
                ]);

            DB::commit();

            // Send notifications to affected consumers
            $this->notifyAffectedConsumers($affectedConsumers, $deprecationData);

            Log::info('API deprecation managed', [
                'deprecation_id' => $deprecationId,
                'endpoint' => $endpoint,
                'method' => $method,
                'version' => $version,
                'status' => $deprecationData['status'],
                'affected_consumers_count' => count($affectedConsumers),
            ]);

            return [
                'success' => true,
                'deprecation_id' => $deprecationId,
                'affected_consumers' => $affectedConsumers,
                'message' => 'API deprecation managed successfully',
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to manage API deprecation', [
                'deprecation_data' => $deprecationData,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to manage deprecation',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get API usage analytics and insights
     *
     * @param  array  $options  Analytics options
     * @return array Analytics result
     */
    public function getUsageAnalytics(array $options = []): array
    {
        try {
            $startDate = $options['start_date'] ?? now()->subDays(30);
            $endDate = $options['end_date'] ?? now();
            $version = $options['version'] ?? null;
            $tenantId = $options['tenant_id'] ?? null;

            $query = DB::table('api_usage_analytics')
                ->whereBetween('usage_date', [$startDate, $endDate]);

            if ($version) {
                $query->where('version', $version);
            }

            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            // Overall statistics
            $overallStats = $query->selectRaw('
                SUM(request_count) as total_requests,
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate,
                COUNT(DISTINCT endpoint) as unique_endpoints,
                COUNT(DISTINCT tenant_id) as unique_tenants
            ')->first();

            // Top endpoints by usage
            $topEndpoints = $query->selectRaw('
                endpoint,
                method,
                SUM(request_count) as total_requests,
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate
            ')
                ->groupBy(['endpoint', 'method'])
                ->orderBy('total_requests', 'desc')
                ->limit(10)
                ->get();

            // Usage trends over time
            $usageTrends = $query->selectRaw('
                usage_date,
                SUM(request_count) as daily_requests,
                AVG(avg_response_time_ms) as daily_avg_response_time,
                AVG(error_rate_percent) as daily_error_rate
            ')
                ->groupBy('usage_date')
                ->orderBy('usage_date')
                ->get();

            // Consumer analytics
            $consumerStats = $query->selectRaw('
                consumer_type,
                COUNT(DISTINCT consumer_id) as unique_consumers,
                SUM(request_count) as total_requests,
                AVG(error_rate_percent) as avg_error_rate
            ')
                ->groupBy('consumer_type')
                ->get();

            return [
                'success' => true,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'overall_statistics' => $overallStats,
                'top_endpoints' => $topEndpoints,
                'usage_trends' => $usageTrends,
                'consumer_statistics' => $consumerStats,
                'generated_at' => now(),
            ];

        } catch (Exception $e) {
            Log::error('Failed to get usage analytics', [
                'options' => $options,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get analytics',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get active API versions
     */
    private function getActiveApiVersions(): array
    {
        return DB::table('api_contracts')
            ->where('is_active', true)
            ->distinct()
            ->pluck('version')
            ->toArray();
    }

    /**
     * Generate specification for specific version
     */
    private function generateVersionSpecification(string $version, bool $includeDeprecated = false): array
    {
        $cacheKey = self::CACHE_PREFIX."spec:{$version}:".($includeDeprecated ? 'full' : 'active');

        return Cache::remember($cacheKey, self::SPEC_CACHE_TTL, function () use ($version, $includeDeprecated) {
            try {
                $query = DB::table('api_contracts')
                    ->where('version', $version)
                    ->where('is_active', true);

                if (! $includeDeprecated) {
                    $query->whereNull('deprecated_at');
                }

                $contracts = $query->get();

                $spec = [
                    'openapi' => '3.0.3',
                    'info' => [
                        'title' => 'ERP Event Management API',
                        'version' => $version,
                        'description' => 'Comprehensive API for ERP Event Management Module',
                        'contact' => [
                            'name' => 'API Support',
                            'email' => 'api-support@example.com',
                        ],
                    ],
                    'servers' => [
                        [
                            'url' => config('app.url')."/api/v{$version}",
                            'description' => 'Production server',
                        ],
                    ],
                    'paths' => [],
                    'components' => [
                        'securitySchemes' => [
                            'bearerAuth' => [
                                'type' => 'http',
                                'scheme' => 'bearer',
                                'bearerFormat' => 'JWT',
                            ],
                        ],
                    ],
                ];

                foreach ($contracts as $contract) {
                    $contractSpec = json_decode($contract->contract_spec, true);
                    $path = $contract->endpoint;
                    $method = strtolower($contract->method);

                    if (! isset($spec['paths'][$path])) {
                        $spec['paths'][$path] = [];
                    }

                    $spec['paths'][$path][$method] = $this->convertToOpenApiOperation($contractSpec);
                }

                return [
                    'success' => true,
                    'specification' => $spec,
                ];

            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Generate combined specification for all versions
     */
    private function generateCombinedSpecification(array $specifications): array
    {
        if (empty($specifications)) {
            return [];
        }

        $combined = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'ERP Event Management API - All Versions',
                'version' => 'combined',
                'description' => 'Combined API specification for all versions',
            ],
            'servers' => [],
            'paths' => [],
        ];

        foreach ($specifications as $version => $spec) {
            // Add version-specific server
            $combined['servers'][] = [
                'url' => config('app.url')."/api/v{$version}",
                'description' => "Version {$version} server",
            ];

            // Merge paths with version prefix
            foreach ($spec['paths'] ?? [] as $path => $methods) {
                $versionedPath = "/v{$version}{$path}";
                $combined['paths'][$versionedPath] = $methods;
            }
        }

        return $combined;
    }

    /**
     * Store specification to file
     */
    private function storeSpecification(string $version, array $specification): void
    {
        $filename = "openapi_v{$version}.json";
        $path = self::DOCUMENTATION_PATH."/specifications/{$filename}";

        Storage::put($path, json_encode($specification, JSON_PRETTY_PRINT));
    }

    /**
     * Generate HTML documentation
     */
    private function generateHtmlDocumentation(array $specification, bool $includeExamples): string
    {
        $html = "<!DOCTYPE html>\n<html>\n<head>\n";
        $html .= "<title>{$specification['info']['title']} - API Documentation</title>\n";
        $html .= "<style>body { font-family: Arial, sans-serif; margin: 40px; }</style>\n";
        $html .= "</head>\n<body>\n";

        $html .= "<h1>{$specification['info']['title']}</h1>\n";
        $html .= "<p>Version: {$specification['info']['version']}</p>\n";
        $html .= "<p>{$specification['info']['description']}</p>\n";

        $html .= "<h2>Endpoints</h2>\n";

        foreach ($specification['paths'] ?? [] as $path => $methods) {
            $html .= "<h3>{$path}</h3>\n";

            foreach ($methods as $method => $operation) {
                $html .= '<h4>'.strtoupper($method)."</h4>\n";
                $html .= '<p>'.($operation['summary'] ?? 'No summary available')."</p>\n";

                if ($includeExamples && isset($operation['examples'])) {
                    $html .= "<h5>Examples</h5>\n";
                    $html .= '<pre>'.json_encode($operation['examples'], JSON_PRETTY_PRINT)."</pre>\n";
                }
            }
        }

        $html .= "</body>\n</html>";

        return $html;
    }

    /**
     * Generate Markdown documentation
     */
    private function generateMarkdownDocumentation(array $specification, bool $includeExamples): string
    {
        $markdown = "# {$specification['info']['title']}\n\n";
        $markdown .= "**Version:** {$specification['info']['version']}\n\n";
        $markdown .= "{$specification['info']['description']}\n\n";

        $markdown .= "## Endpoints\n\n";

        foreach ($specification['paths'] ?? [] as $path => $methods) {
            $markdown .= "### {$path}\n\n";

            foreach ($methods as $method => $operation) {
                $markdown .= '#### '.strtoupper($method)."\n\n";
                $markdown .= ($operation['summary'] ?? 'No summary available')."\n\n";

                if ($includeExamples && isset($operation['examples'])) {
                    $markdown .= "##### Examples\n\n";
                    $markdown .= "```json\n".json_encode($operation['examples'], JSON_PRETTY_PRINT)."\n```\n\n";
                }
            }
        }

        return $markdown;
    }

    /**
     * Store documentation to file
     */
    private function storeDocumentation(string $version, string $format, $documentation): string
    {
        $extension = match ($format) {
            'html' => 'html',
            'markdown' => 'md',
            'json' => 'json',
            default => 'txt'
        };

        $filename = "api_docs_v{$version}.{$extension}";
        $path = self::DOCUMENTATION_PATH."/generated/{$filename}";

        $content = is_string($documentation) ? $documentation : json_encode($documentation, JSON_PRETTY_PRINT);
        Storage::put($path, $content);

        return $filename;
    }

    /**
     * Generate usage analytics for documentation
     */
    private function generateUsageAnalytics(string $version): array
    {
        $analytics = DB::table('api_usage_analytics')
            ->where('version', $version)
            ->where('usage_date', '>=', now()->subDays(30))
            ->selectRaw('
                endpoint,
                method,
                SUM(request_count) as total_requests,
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate
            ')
            ->groupBy(['endpoint', 'method'])
            ->orderBy('total_requests', 'desc')
            ->get();

        return $analytics->toArray();
    }

    /**
     * Calculate new average for incremental updates
     */
    private function calculateNewAverage(float $currentAvg, int $currentCount, float $newValue): float
    {
        if ($currentCount == 0) {
            return $newValue;
        }

        return (($currentAvg * $currentCount) + $newValue) / ($currentCount + 1);
    }

    /**
     * Calculate error rate percentage
     */
    private function calculateErrorRate(int $successCount, int $errorCount): float
    {
        $totalCount = $successCount + $errorCount;

        if ($totalCount == 0) {
            return 0;
        }

        return round(($errorCount / $totalCount) * 100, 2);
    }

    /**
     * Update status code distribution
     */
    private function updateStatusCodeDistribution(array $currentDistribution, int $statusCode): array
    {
        $statusCodeStr = (string) $statusCode;

        if (isset($currentDistribution[$statusCodeStr])) {
            $currentDistribution[$statusCodeStr]++;
        } else {
            $currentDistribution[$statusCodeStr] = 1;
        }

        return $currentDistribution;
    }

    /**
     * Identify affected consumers for deprecation
     */
    private function identifyAffectedConsumers(string $endpoint, string $method, string $version): array
    {
        $consumers = DB::table('api_usage_analytics')
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->where('version', $version)
            ->where('usage_date', '>=', now()->subDays(30))
            ->selectRaw('
                consumer_type,
                consumer_id,
                tenant_id,
                SUM(request_count) as total_requests,
                MAX(usage_date) as last_used
            ')
            ->groupBy(['consumer_type', 'consumer_id', 'tenant_id'])
            ->having('total_requests', '>', 0)
            ->get();

        return $consumers->toArray();
    }

    /**
     * Notify affected consumers about deprecation
     */
    private function notifyAffectedConsumers(array $consumers, array $deprecationData): void
    {
        foreach ($consumers as $consumer) {
            // Log notification (in real implementation, send actual notifications)
            Log::info('Deprecation notification sent', [
                'consumer_type' => $consumer->consumer_type,
                'consumer_id' => $consumer->consumer_id,
                'tenant_id' => $consumer->tenant_id,
                'endpoint' => $deprecationData['endpoint'],
                'method' => $deprecationData['method'],
                'version' => $deprecationData['version'],
                'deprecation_status' => $deprecationData['status'],
            ]);
        }
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
            'responses' => [],
            'security' => [['bearerAuth' => []]],
        ];

        // Convert query parameters
        if (isset($contractSpec['request']['query_parameters'])) {
            foreach ($contractSpec['request']['query_parameters'] as $param => $spec) {
                $operation['parameters'][] = [
                    'name' => $param,
                    'in' => 'query',
                    'required' => $spec['required'] ?? false,
                    'schema' => [
                        'type' => $spec['type'] ?? 'string',
                    ],
                    'description' => $spec['description'] ?? '',
                ];
            }
        }

        // Convert path parameters
        if (isset($contractSpec['request']['path_parameters'])) {
            foreach ($contractSpec['request']['path_parameters'] as $param => $spec) {
                $operation['parameters'][] = [
                    'name' => $param,
                    'in' => 'path',
                    'required' => true,
                    'schema' => [
                        'type' => $spec['type'] ?? 'string',
                    ],
                    'description' => $spec['description'] ?? '',
                ];
            }
        }

        // Convert request body
        if (isset($contractSpec['request']['body_schema'])) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => $contractSpec['request']['body_schema'],
                    ],
                ],
            ];
        }

        // Convert responses
        if (isset($contractSpec['responses'])) {
            foreach ($contractSpec['responses'] as $code => $response) {
                $operation['responses'][$code] = [
                    'description' => $response['description'] ?? 'Response',
                    'content' => [
                        'application/json' => [
                            'schema' => $response['body_schema'] ?? ['type' => 'object'],
                        ],
                    ],
                ];
            }
        }

        // Add tags for organization
        if (isset($contractSpec['tags'])) {
            $operation['tags'] = $contractSpec['tags'];
        }

        return $operation;
    }
}
