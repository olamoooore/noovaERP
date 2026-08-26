<?php

namespace App\Http\Middleware;

use App\Services\EntityAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user has access to the resolved legal entity.
 * Must be applied AFTER ResolveEntityContext middleware.
 *
 * Mode semantics (per Pillar 2 Part 1 §2.1 / Pillar 1 P0-5 / P0-13):
 *
 *   - Default (no third argument): fails open. If no entity context
 *     is set (e.g. single-entity tenant that has not yet provisioned
 *     a legal_entities row), the request is allowed through. This
 *     matches the historical behaviour and is needed for backward
 *     compatibility with single-entity tenants that never resolve an
 *     entity. Per the report, this is the wrong default for
 *     multi-entity tenants — the report calls this out as the
 *     "fails open" path.
 *
 *   - `?strict=true`: fails closed. If no entity context is set,
 *     the request is rejected with 403 (canonical reason code
 *     ENTITY_CONTEXT_REQUIRED) and an audit log entry. Apply this to
 *     routes that must have an entity context — typically any
 *     route that mutates per-entity data.
 *
 *   - `?strict=false` (or `0`): explicit fails-open. Same as default.
 *
 * Routing examples:
 *   ->middleware(\App\Http\Middleware\EnsureEntityAccess::class)
 *     // historical behaviour, fails open
 *   ->middleware(\App\Http\Middleware\EnsureEntityAccess::class.':viewer,strict')
 *     // minimumRole=viewer, strict=true (default-deny on missing context)
 *
 * Note: per the report, even with strict=true, the middleware is a
 * gate, not a query filter. Wiring per-entity scoping into controllers
 * is a separate, larger work item (P0-13 §2.2 — query-level filters on
 * per-entity tables).
 */
class EnsureEntityAccess
{
    public const REASON_MISSING_CONTEXT = 'ENTITY_CONTEXT_REQUIRED';
    public const REASON_INSUFFICIENT_ROLE = 'ENTITY_ACCESS_DENIED';

    public function __construct(protected EntityAccessService $accessService) {}

    public function handle(
        Request $request,
        Closure $next,
        string $minimumRole = 'viewer',
        string|bool|null $strict = null,
    ): Response {
        $entityId = $request->attributes->get('legal_entity_id');
        $user = $request->user();

        // Default: not strict. Per the report this is the historical
        // single-tenant shortcut. The strict mode is opt-in.
        $isStrict = $this->parseStrict($strict);

        if (! $entityId || ! $user) {
            if ($isStrict) {
                Log::warning('EnsureEntityAccess: missing context, strict-mode BLOCK', [
                    'route' => $request->path(),
                    'user_id' => $user?->id,
                    'minimum_role' => $minimumRole,
                    'reason' => self::REASON_MISSING_CONTEXT,
                ]);
                return response()->json([
                    'message' => 'A legal entity context is required for this operation.',
                    'reason_code' => self::REASON_MISSING_CONTEXT,
                ], 403);
            }
            // Fails open (historical behaviour).
            return $next($request);
        }

        if (! $this->accessService->hasRole($user->id, $entityId, $minimumRole)) {
            return response()->json([
                'message' => 'You do not have access to this entity.',
                'reason_code' => self::REASON_INSUFFICIENT_ROLE,
                'required_role' => $minimumRole,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Parse the strict argument as it arrives from the route middleware
     * string syntax (\App\Http\Middleware\EnsureEntityAccess::class.':viewer,strict')
     * or programmatic invocation. Laravel converts string "true"/"false"
     * to the literal strings; we accept either.
     */
    private function parseStrict(string|bool|null $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === null) {
            return false;
        }
        $normalized = strtolower(trim($raw));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'strict'], true);
    }
}
