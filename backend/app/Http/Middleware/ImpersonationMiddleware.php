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

        // Issue #217: the token alone must not authorize the swap — verify the
        // presenter (the Sanctum-authenticated user BEFORE any swap) is either
        // the original actor or a platform super-admin. Otherwise any
        // authenticated tenant user holding a leaked token could become the
        // target user.
        $presenter = $request->user();

        if (! $presenter) {
            return response()->json([
                'message' => 'Authentication is required to present an impersonation token.',
            ], 401);
        }

        $isActor = (int) $presenter->id === (int) $impersonation->actor_id;
        $isSuperAdmin = (bool) ($presenter->is_super_admin ?? false)
            || ($presenter->role ?? null) === 'platform_super_admin';

        if (! $isActor && ! $isSuperAdmin) {
            \Log::warning('ImpersonationMiddleware: token presented by an unauthorized user', [
                'impersonation_id' => $impersonation->id,
                'presenter_id' => $presenter->id,
                'actor_id' => $impersonation->actor_id,
            ]);

            return response()->json([
                'message' => 'You are not authorized to use this impersonation token.',
            ], 403);
        }

        $targetUser = User::find($impersonation->target_user_id);
        if (! $targetUser) {
            return response()->json([
                'message' => 'Impersonated user not found.',
            ], 401);
        }

        // Initialize tenancy for the impersonated user's tenant.
        // Issue #219: support impersonation into a SUSPENDED tenant is the exact
        // scenario impersonation exists for, and the presenter is verified above
        // as the actor or a super-admin — bypass the central suspension gate.
        $tenant = app(\App\Models\Tenant::class)->find($impersonation->tenant_id);
        if ($tenant) {
            \App\Tenancy\TenantSuspensionGuard::withoutEnforcement(function () use ($tenant) {
                app(\Stancl\Tenancy\Tenancy::class)->initialize($tenant);
            });
        }

        // Bind the impersonated user as the authenticated user
        auth()->setUser($targetUser);

        // Issue #218: mark the impersonation context as a request ATTRIBUTE
        // (not just merged input) so SetTenantFromUserPreference can detect it
        // and skip re-resolving the tenant from the swapped user's default.
        $request->attributes->set('_impersonation', [
            'id' => $impersonation->id,
            'actor_id' => $impersonation->actor_id,
            'target_user_id' => $impersonation->target_user_id,
            'tenant_id' => $impersonation->tenant_id,
        ]);

        $response = $next($request);

        return $response;
    }
}
