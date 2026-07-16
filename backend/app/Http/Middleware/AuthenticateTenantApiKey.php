<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\SuperAdmin\Domain\TenantApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTenantApiKey
{
    /**
     * Resolve the tenant from a Bearer API key and attach it to the request.
     *
     * The ERP module sends:
     *   Authorization: Bearer ten_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer || ! str_starts_with($bearer, 'ten_')) {
            return response()->json([
                'error' => 'Missing or malformed API key. Expected Authorization: Bearer ten_...',
            ], 401);
        }

        $apiKey = TenantApiKey::findByRawKey($bearer);

        if (! $apiKey) {
            return response()->json(['error' => 'Invalid API key.'], 401);
        }

        if (! $apiKey->is_active) {
            return response()->json(['error' => 'API key is disabled.'], 403);
        }

        if ($apiKey->isExpired()) {
            return response()->json(['error' => 'API key has expired.'], 403);
        }

        // Record last usage without blocking the request
        $apiKey->recordUsage();

        // Attach tenant context to the request for the controller
        $request->attributes->set('tenant_id', $apiKey->tenant_id);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
