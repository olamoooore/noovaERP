<?php

/**
 * @deprecated Legacy single-tenant commerce stack — archived per MRD
 *             ecommerce-Enterprice Appendix A (FR-ARC-01). Frozen 2026-09-28:
 *             no new features; superseded by the generic modules/Storefront.
 *             Do not extend, copy or reuse as a platform foundation.
 */

namespace Modules\Ecommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ecommerce\Models\WebCustomerSession;

class WebCustomerAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Bearer token required',
            ], 401);
        }

        // Debug: Check if tenant is initialized
        Log::debug('WebCustomerAuth: Checking tenant context', [
            'tenancy_initialized' => tenancy()->initialized,
            'tenant_id' => tenancy()->initialized ? tenancy()->tenant?->id : null,
            'search_path' => DB::select('SHOW search_path')[0]->search_path ?? 'unknown',
        ]);

        if (! tenancy()->initialized || ! tenancy()->tenant?->id) {
            Log::warning('WebCustomerAuth: Missing tenant context');

            return response()->json([
                'error' => 'Tenant context required',
                'message' => 'Unable to resolve tenant for this request',
            ], 403);
        }

        $session = WebCustomerSession::with('customer')
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (! $session || ! $session->isValid()) {
            Log::debug('WebCustomerAuth: Session not found or invalid', [
                'token_prefix' => substr($token, 0, 10).'...',
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired token',
            ], 401);
        }

        // Update last active
        $session->updateLastActive();

        // Attach customer to request
        $request->attributes->set('web_customer', $session->customer);
        $request->attributes->set('web_session', $session);

        return $next($request);
    }
}
