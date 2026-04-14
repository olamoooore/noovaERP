<?php

namespace App\Http\Middleware;

use App\SuperAdmin\Application\FeatureGateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureEntitlementAccess
 *
 * Gates a route behind the canonical entitlement snapshot (`tenant_entitlements`).
 * This is the replacement for the legacy `feature:{key}` middleware which reads
 * from the unsafe `tenant_features` table (which lacks `tenant_id` and is not
 * the authoritative access-control source).
 *
 * Usage in routes:
 *   Route::middleware('entitlement:pos')->group(...)
 *   Route::middleware('entitlement:inventory')->group(...)
 *
 * Resolution order:
 *   1. Core features (is_core on `features` table) pass unconditionally
 *   2. FeatureGateService::check() reads `tenant_entitlements`
 *   3. 403 if not entitled
 *
 *逐步 migration: existing `feature:{key}` middleware calls still work but are
 * marked as legacy. New routes should use `entitlement:{key}`.
 */
class EnsureEntitlementAccess
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        // ── 1. Super-admin bypass ──────────────────────────────────────────────
        $user = $request->user();
        if ($user && ($user->is_super_admin ?? false)) {
            return $next($request);
        }

        // ── 2. Core features always pass ─────────────────────────────────────
        $feature = \App\Models\Feature::query()->where('key', $featureKey)->first();
        if ($feature && $feature->is_core) {
            return $next($request);
        }

        // ── 3. Canonical entitlement check ────────────────────────────────────
        $tenantId = $this->currentTenantId();

        if ($tenantId !== null) {
            $result = $this->featureGate->check($tenantId, $featureKey);

            if ($result['is_entitled']) {
                return $next($request);
            }

            return response()->json([
                'message' => "Feature '{$featureKey}' is not enabled for this account.",
            ], 403);
        }

        // ── 4. No tenant context — fail closed ────────────────────────────────
        return response()->json([
            'message' => 'No tenant context available to verify feature access.',
        ], 403);
    }

    private function currentTenantId(): ?string
    {
        if (!function_exists('tenant') || !tenant()) {
            return null;
        }

        return (string) tenant('id');
    }
}