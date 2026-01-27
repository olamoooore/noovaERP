<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Modules\EventManagement\Services\ApiContractService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ApiContractValidationMiddleware - Task 12.1
 * 
 * Middleware for API contract validation, request/response schema validation,
 * contract versioning and compatibility checking, and contract violation
 * monitoring and alerting.
 * 
 * Requirements: 28.1, 28.2
 */
class ApiContractValidationMiddleware
{
    private ApiContractService $contractService;
    private const CACHE_PREFIX = 'api_contract:';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(ApiContractService $contractService)
    {
        $this->contractService = $contractService;
    }

    /**
     * Handle an incoming request
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Skip validation for internal routes or health checks
        if ($this->shouldSkipValidation($request)) {
            return $next($request);
        }

        try {
            // Extract API version and endpoint information
            $apiVersion = $this->extractApiVersion($request);
            $endpoint = $this->normalizeEndpoint($request);
            $method = $request->getMethod();

            // Get contract for this endpoint
            $contract = $this->getEndpointContract($endpoint, $method, $apiVersion);

            if (!$contract) {
                // Log missing contract but don't block request
                $this->logContractViolation($request, 'missing_contract', [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'version' => $apiVersion
                ]);
                
                return $next($request);
            }

            // Validate request against contract
            $requestValidation = $this->validateRequest($request, $contract);
            if (!$requestValidation['valid']) {
                return $this->handleContractViolation($request, 'request_validation_failed', $requestValidation['errors']);
            }

            // Process the request
            $response = $next($request);

            // Validate response against contract
            $responseValidation = $this->validateResponse($response, $contract);
            if (!$responseValidation['valid']) {
                $this->logContractViolation($request, 'response_validation_failed', $responseValidation['errors']);
                // Don't block response, but log for monitoring
            }

            // Log successful contract validation
            $this->logContractCompliance($request, $contract);

            return $response;

        } catch (\Exception $e) {
            Log::error('API contract validation error', [
                'request_id' => $request->header('X-Request-ID'),
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'error' => $e->getMessage()
            ]);

            // Don't block request on validation errors, just log
            return $next($request);
        }
    }

    /**
     * Check if validation should be skipped for this request
     */
    private function shouldSkipValidation(Request $request): bool
    {
        $skipPaths = [
            '/health',
            '/metrics',
            '/status',
            '/_internal'
        ];

        $path = $request->getPathInfo();
        
        foreach ($skipPaths as $skipPath) {
            if (str_starts_with($path, $skipPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract API version from request
     */
    private function extractApiVersion(Request $request): string
    {
        // Try header first
        $version = $request->header('API-Version');
        if ($version) {
            return $version;
        }

        // Try URL path
        $path = $request->getPathInfo();
        if (preg_match('/\/api\/v(\d+(?:\.\d+)?)\//', $path, $matches)) {
            return $matches[1];
        }

        // Default version
        return '1.0';
    }

    /**
     * Normalize endpoint path for contract lookup
     */
    private function normalizeEndpoint(Request $request): string
    {
        $path = $request->getPathInfo();
        
        // Remove API version prefix
        $path = preg_replace('/\/api\/v\d+(?:\.\d+)?/', '', $path);
        
        // Replace path parameters with placeholders
        $path = preg_replace('/\/\d+/', '/{id}', $path);
        $path = preg_replace('/\/[a-f0-9-]{36}/', '/{uuid}', $path);
        
        return $path;
    }

    /**
     * Get contract for endpoint
     */
    private function getEndpointContract(string $endpoint, string $method, string $version): ?array
    {
        $cacheKey = self::CACHE_PREFIX . "contract:{$version}:{$method}:{$endpoint}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($endpoint, $method, $version) {
            return $this->contractService->getContract($endpoint, $method, $version);
        });
    }

    /**
     * Validate request against contract
     */
    private function validateRequest(Request $request, array $contract): array
    {
        $errors = [];

        // Validate required headers
        if (isset($contract['request']['headers'])) {
            foreach ($contract['request']['headers'] as $header => $spec) {
                if ($spec['required'] && !$request->hasHeader($header)) {
                    $errors[] = "Missing required header: {$header}";
                }
            }
        }

        // Validate query parameters
        if (isset($contract['request']['query_parameters'])) {
            $queryParams = $request->query();
            foreach ($contract['request']['query_parameters'] as $param => $spec) {
                if ($spec['required'] && !isset($queryParams[$param])) {
                    $errors[] = "Missing required query parameter: {$param}";
                }
                
                if (isset($queryParams[$param]) && isset($spec['type'])) {
                    if (!$this->validateParameterType($queryParams[$param], $spec['type'])) {
                        $errors[] = "Invalid type for query parameter {$param}: expected {$spec['type']}";
                    }
                }
            }
        }

        // Validate request body schema
        if (isset($contract['request']['body_schema']) && $request->getContent()) {
            $bodyValidation = $this->validateJsonSchema(
                $request->getContent(),
                $contract['request']['body_schema']
            );
            
            if (!$bodyValidation['valid']) {
                $errors = array_merge($errors, $bodyValidation['errors']);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate response against contract
     */
    private function validateResponse(SymfonyResponse $response, array $contract): array
    {
        $errors = [];
        $statusCode = $response->getStatusCode();

        // Check if status code is allowed
        if (isset($contract['responses'])) {
            $allowedCodes = array_keys($contract['responses']);
            if (!in_array($statusCode, $allowedCodes) && !in_array('default', $allowedCodes)) {
                $errors[] = "Unexpected status code: {$statusCode}";
            }
        }

        // Validate response headers
        $responseSpec = $contract['responses'][$statusCode] ?? $contract['responses']['default'] ?? null;
        if ($responseSpec && isset($responseSpec['headers'])) {
            foreach ($responseSpec['headers'] as $header => $spec) {
                if ($spec['required'] && !$response->headers->has($header)) {
                    $errors[] = "Missing required response header: {$header}";
                }
            }
        }

        // Validate response body schema
        if ($responseSpec && isset($responseSpec['body_schema']) && $response->getContent()) {
            $bodyValidation = $this->validateJsonSchema(
                $response->getContent(),
                $responseSpec['body_schema']
            );
            
            if (!$bodyValidation['valid']) {
                $errors = array_merge($errors, $bodyValidation['errors']);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate parameter type
     */
    private function validateParameterType($value, string $expectedType): bool
    {
        return match($expectedType) {
            'string' => is_string($value),
            'integer' => is_numeric($value) && (int)$value == $value,
            'number' => is_numeric($value),
            'boolean' => in_array(strtolower($value), ['true', 'false', '1', '0']),
            'array' => is_array($value),
            default => true
        };
    }

    /**
     * Validate JSON schema
     */
    private function validateJsonSchema(string $json, array $schema): array
    {
        try {
            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'valid' => false,
                    'errors' => ['Invalid JSON format']
                ];
            }

            // Basic schema validation (simplified)
            $errors = $this->validateSchemaRecursive($data, $schema, '');

            return [
                'valid' => empty($errors),
                'errors' => $errors
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'errors' => ['Schema validation error: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Recursive schema validation
     */
    private function validateSchemaRecursive($data, array $schema, string $path): array
    {
        $errors = [];

        // Check required fields
        if (isset($schema['required']) && is_array($data)) {
            foreach ($schema['required'] as $field) {
                if (!array_key_exists($field, $data)) {
                    $errors[] = "Missing required field: {$path}.{$field}";
                }
            }
        }

        // Check field types
        if (isset($schema['properties']) && is_array($data)) {
            foreach ($schema['properties'] as $field => $fieldSchema) {
                if (array_key_exists($field, $data)) {
                    $fieldPath = $path ? "{$path}.{$field}" : $field;
                    $fieldErrors = $this->validateFieldType($data[$field], $fieldSchema, $fieldPath);
                    $errors = array_merge($errors, $fieldErrors);
                }
            }
        }

        return $errors;
    }

    /**
     * Validate field type
     */
    private function validateFieldType($value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['type'])) {
            $valid = match($schema['type']) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_numeric($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_array($value) || is_object($value),
                default => true
            };

            if (!$valid) {
                $errors[] = "Invalid type for field {$path}: expected {$schema['type']}";
            }
        }

        // Recursive validation for objects and arrays
        if (isset($schema['properties']) && (is_array($value) || is_object($value))) {
            $nestedErrors = $this->validateSchemaRecursive($value, $schema, $path);
            $errors = array_merge($errors, $nestedErrors);
        }

        return $errors;
    }

    /**
     * Handle contract violation
     */
    private function handleContractViolation(Request $request, string $violationType, array $errors): Response
    {
        $this->logContractViolation($request, $violationType, $errors);

        // For now, return validation error response
        // In production, this might be configurable (warn vs block)
        return response()->json([
            'error' => 'API Contract Violation',
            'type' => $violationType,
            'details' => $errors,
            'request_id' => $request->header('X-Request-ID')
        ], 400);
    }

    /**
     * Log contract violation
     */
    private function logContractViolation(Request $request, string $violationType, array $details): void
    {
        Log::warning('API contract violation', [
            'violation_type' => $violationType,
            'request_id' => $request->header('X-Request-ID'),
            'endpoint' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'details' => $details,
            'timestamp' => now()
        ]);

        // Store violation for monitoring
        $this->contractService->recordViolation($request, $violationType, $details);
    }

    /**
     * Log successful contract compliance
     */
    private function logContractCompliance(Request $request, array $contract): void
    {
        // Only log compliance for monitoring, not as regular log entry
        $this->contractService->recordCompliance($request, $contract);
    }
}