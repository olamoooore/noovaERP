<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        // Accept from upstream or generate a new one
        $correlationId = $request->header(self::HEADER) ?? (string) Str::uuid();

        // Bind to the request for downstream use
        $request->headers->set(self::HEADER, $correlationId);

        // Push into log context so every log line in this request carries it
        Log::withContext([
            'correlation_id' => $correlationId,
            'tenant_id' => $request->user()?->tenant_id ?? null,
        ]);

        $response = $next($request);

        // Echo back in response header for client tracing
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
