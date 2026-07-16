<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class RequestValidationMiddleware
{
    /**
     * Handle an incoming request.
     * Requirements: 7.5 - Request validation and sanitization
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Validate common request headers
        $this->validateHeaders($request);

        // Sanitize input data
        $this->sanitizeInput($request);

        // Validate request size
        $this->validateRequestSize($request);

        // Add request ID if not present
        $this->ensureRequestId($request);

        return $next($request);
    }

    /**
     * Validate required headers
     */
    protected function validateHeaders(Request $request): void
    {
        $requiredHeaders = [
            'Content-Type' => 'required_if:method,POST,PUT,PATCH',
            'Accept' => 'required',
        ];

        $headers = [];
        foreach ($requiredHeaders as $header => $rule) {
            $headers[$header] = $request->header($header);
        }

        $validator = Validator::make($headers, $requiredHeaders, [
            'Content-Type.required_if' => 'Content-Type header is required for POST, PUT, and PATCH requests',
            'Accept.required' => 'Accept header is required',
        ]);

        if ($validator->fails()) {
            $this->throwValidationError('Invalid headers', $validator->errors()->toArray());
        }

        // Validate Content-Type for JSON APIs
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            $contentType = $request->header('Content-Type');
            if ($contentType && ! str_contains($contentType, 'application/json')) {
                $this->throwValidationError('Invalid Content-Type', [
                    'Content-Type' => ['Content-Type must be application/json for API requests'],
                ]);
            }
        }
    }

    /**
     * Sanitize input data
     */
    protected function sanitizeInput(Request $request): void
    {
        if (! $request->isJson()) {
            return;
        }

        $input = $request->json()->all();
        $sanitized = $this->recursiveSanitize($input);

        // Replace the input with sanitized version
        $request->merge($sanitized);
    }

    /**
     * Recursively sanitize input data
     */
    protected function recursiveSanitize($data): array
    {
        if (! is_array($data)) {
            return $data;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->recursiveSanitize($value);
            } elseif (is_string($value)) {
                // Basic sanitization
                $sanitized[$key] = $this->sanitizeString($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize string values
     */
    protected function sanitizeString(string $value): string
    {
        // Remove null bytes
        $value = str_replace("\0", '', $value);

        // Trim whitespace
        $value = trim($value);

        // Remove control characters except newlines and tabs
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return $value;
    }

    /**
     * Validate request size
     */
    protected function validateRequestSize(Request $request): void
    {
        $maxSize = 1024 * 1024; // 1MB default
        $contentLength = $request->header('Content-Length');

        if ($contentLength && $contentLength > $maxSize) {
            $this->throwValidationError('Request too large', [
                'size' => ['Request size exceeds maximum allowed size of '.($maxSize / 1024 / 1024).'MB'],
            ]);
        }

        // Check actual content size for JSON requests
        if ($request->isJson()) {
            $content = $request->getContent();
            if (strlen($content) > $maxSize) {
                $this->throwValidationError('Request too large', [
                    'size' => ['Request content exceeds maximum allowed size'],
                ]);
            }
        }
    }

    /**
     * Ensure request has a unique ID for tracing
     */
    protected function ensureRequestId(Request $request): void
    {
        if (! $request->header('X-Request-ID')) {
            $requestId = uniqid('req_', true);
            $request->headers->set('X-Request-ID', $requestId);
        }
    }

    /**
     * Throw validation error response
     */
    protected function throwValidationError(string $message, array $errors): void
    {
        $response = response()->json([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => $message,
                'details' => $errors,
                'request_id' => request()->header('X-Request-ID', uniqid()),
            ],
        ], Response::HTTP_BAD_REQUEST);

        abort($response);
    }
}
