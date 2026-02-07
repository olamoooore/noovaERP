<?php

namespace Modules\Ecommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Ecommerce\Models\WebCustomerSession;

class WebCustomerAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Bearer token required',
            ], 401);
        }

        $session = WebCustomerSession::with('customer')
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session || !$session->isValid()) {
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