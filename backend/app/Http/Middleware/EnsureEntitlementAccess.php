<?php

namespace App\Http\Middleware;

use App\SuperAdmin\Application\FeatureGateService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Tenancy;
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
        private readonly Tenancy $tenancy,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        // ── 1. Super-admin bypass ──────────────────────────────────────────────
        $user = $request->user();
        if ($user && ($user->is_super_admin ?? false)) {
            return $next($request);
        }

        // ── 2. Core features always pass ─────────────────────────────────────
        // Query features from public schema explicitly to avoid tenant search_path interference
        try {
            DB::statement("SET search_path TO public");
            $feature = \App\Models\Feature::query()->where('key', $featureKey)->first();
        } catch (\Throwable $e) {
            $feature = null;
        } finally {
            // search_path will be restored by EnsureTenantSearchPath for subsequent middleware
        }
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

        // ── 4. No tenant context — try Host-header domain resolution (agent requests) ──
        $resolved = $this->resolveTenantFromHost($request);

        if ($resolved !== null) {
            $result = $this->featureGate->check((string) $resolved->id, $featureKey);

            if ($result['is_entitled']) {
                return $next($request);
            }

            return response()->json([
                'message' => "Feature '{$featureKey}' is not enabled for this account.",
            ], 403);
        }

        // ── 6. Nothing worked — fail closed ───────────────────────────────────
        return response()->json([
            'message' => 'No tenant context available to verify feature access.',
        ], 403);
    }

    /**
     * Attempt to resolve and initialize a tenant from the request Host header.
     * Used as a fallback for agent requests that bypass Sanctum authentication.
     *
     * @return \App\Models\Tenant|null
     */
    private function resolveTenantFromHost(Request $request): ?\App\Models\Tenant
    {
        $host = $request->getHost();

        if (!$host) {
            return null;
        }

        try {
            $domain = DB::table('domains')->where('domain', $host)->first();

            if ($domain) {
                $tenant = \App\Models\Tenant::find($domain->tenant_id);

                if ($tenant) {
                    Log::info('EnsureEntitlementAccess: Initializing tenant from Host header', [
                        'host'      => $host,
                        'tenant_id' => $tenant->id,
                    ]);

                    $this->tenancy->initialize($tenant);

                    return $tenant;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('EnsureEntitlementAccess: Domain-based tenant resolution failed', [
                'host'  => $host,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function currentTenantId(): ?string
    {
        if (!function_exists('tenant') || !tenant()) {
            return null;
        }

        return (string) tenant('id');
    }
}