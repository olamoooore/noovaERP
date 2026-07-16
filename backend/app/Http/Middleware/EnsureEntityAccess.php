<?php

namespace App\Http\Middleware;

use App\Services\EntityAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user has access to the resolved legal entity.
 * Must be applied AFTER ResolveEntityContext middleware.
 */
class EnsureEntityAccess
{
    public function __construct(protected EntityAccessService $accessService) {}

    public function handle(Request $request, Closure $next, string $minimumRole = 'viewer'): Response
    {
        $entityId = $request->attributes->get('legal_entity_id');
        $user = $request->user();

        // If no entity context is set, skip check (single-entity tenants)
        if (! $entityId || ! $user) {
            return $next($request);
        }

        if (! $this->accessService->hasRole($user->id, $entityId, $minimumRole)) {
            return response()->json([
                'message' => 'You do not have access to this entity.',
            ], 403);
        }

        return $next($request);
    }
}
