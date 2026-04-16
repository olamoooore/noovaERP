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
 * @deprecated Use EnsureEntitlementAccess (middleware alias 'entitlement') instead.
 *             This middleware is no longer registered as a route middleware alias
 *             (removed from bootstrap/app.php). All routes now use 'entitlement:{key}'.
 *             Kept only for reference during migration period — safe to delete once
 *             the team confirms no external packages reference it.
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
