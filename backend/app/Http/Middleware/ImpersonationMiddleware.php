<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Impersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Impersonation-Token');

        if (! $token) {
            return $next($request);
        }

        $hashedToken = hash('sha256', $token);

        $impersonation = Impersonation::where('token', $hashedToken)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        if (! $impersonation) {
            return response()->json([
                'message' => 'Invalid or expired impersonation token.',
            ], 401);
        }

        $targetUser = User::find($impersonation->target_user_id);
        if (! $targetUser) {
            return response()->json([
                'message' => 'Impersonated user not found.',
            ], 401);
        }

        // Initialize tenancy for the impersonated user's tenant
        $tenant = app(\App\Models\Tenant::class)->find($impersonation->tenant_id);
        if ($tenant) {
            app(\Stancl\Tenancy\Tenancy::class)->initialize($tenant);
        }

        // Bind the impersonated user as the authenticated user
        auth()->setUser($targetUser);

        // Add impersonation context to the request for downstream use
        $request->merge([
            '_impersonation' => [
                'id' => $impersonation->id,
                'actor_id' => $impersonation->actor_id,
                'target_user_id' => $impersonation->target_user_id,
                'tenant_id' => $impersonation->tenant_id,
            ],
        ]);

        $response = $next($request);

        return $response;
    }
}
