<?php

namespace App\Http\Middleware;

use App\Models\Feature;
use App\SuperAdmin\Application\FeatureGateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureFeatureEnabled
 *
 * Gates a route behind a feature-flag check. The check is now backed by the
 * canonical `tenant_entitlements` snapshot (via FeatureGateService) rather than
 * the legacy `tenant_features` table, which lacks tenant identity and is
 * being retired.
 *
 * Resolution order:
 *   1. Core features — always allowed (no DB query needed)
 *   2. FeatureGateService::check() — reads `tenant_entitlements` (new path)
 *   3. Legacy Feature model — fallback when no tenant context is available
 *      (CLI, tests, central routes) or when FeatureGateService is unavailable
 *
 * Usage in routes:  ->middleware('feature:pos')
 */
class EnsureFeatureEnabled
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        // ── 1. Core features pass unconditionally ────────────────────────────
        $feature = Feature::query()->where('key', $featureKey)->first();

        if ($feature && $feature->is_core) {
            return $next($request);
        }

        // ── 2. Canonical entitlement check (tenant_entitlements) ─────────────
        $tenantId = $this->currentTenantId();

        if ($tenantId !== null) {
            $result = $this->featureGate->check($tenantId, $featureKey);

            if (!$result['is_entitled']) {
                abort(403, "Feature '{$featureKey}' is not enabled for this account.");
            }

            return $next($request);
        }

        // ── 3. Legacy fallback (no tenant context — CLI/tests/central routes) ─
        if (!$feature) {
            abort(403, "Feature '{$featureKey}' is not available.");
        }

        if (!$feature->isEnabledForTenant()) {
            abort(403, "Feature '{$featureKey}' is not enabled for this tenant.");
        }

        return $next($request);
    }

    private function currentTenantId(): ?string
    {
        if (!function_exists('tenant') || !tenant()) {
            return null;
        }

        return (string) tenant('id');
    }
}
