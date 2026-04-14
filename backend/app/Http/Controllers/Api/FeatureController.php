<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\TenantFeature;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Http\Request;

/**
 * FeatureController — lists and toggles features for the current tenant.
 *
 * Entitlement checks now read from `tenant_entitlements` via FeatureGateService
 * (the canonical platform entitlement snapshot) rather than the legacy
 * `tenant_features` table, which lacks tenant identity and is being retired.
 *
 * The `toggle()` action still writes to `tenant_features` for backward-compat
 * with any existing consumers; this will be migrated to the control-plane
 * selection API in Phase 3.
 */
class FeatureController extends Controller
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {}

    public function index()
    {
        $tenantId = $this->currentTenantId();

        // If we have a tenant context, resolve entitlements from the canonical snapshot.
        $entitledKeys = $tenantId
            ? collect($this->featureGate->all($tenantId))->pluck('key')->flip()
            : collect();

        $features = Feature::all()->map(function (Feature $feature) use ($entitledKeys, $tenantId) {
            // Core features are always enabled regardless of entitlement record.
            if ($feature->is_core) {
                return $this->formatFeature($feature, true);
            }

            // Use canonical entitlement snapshot when a tenant context is available.
            if ($tenantId !== null) {
                return $this->formatFeature($feature, $entitledKeys->has($feature->key));
            }

            // No tenant context (e.g. CLI, tests) — fall back to legacy row.
            $tenantFeature = TenantFeature::where('feature_id', $feature->id)->first();
            return $this->formatFeature($feature, (bool) $tenantFeature?->is_enabled);
        });

        return response()->json($features);
    }

    public function toggle(Request $request, $id)
    {
        $feature = Feature::findOrFail($id);

        if ($feature->is_core) {
            return response()->json(['message' => 'Cannot disable core features'], 403);
        }

        $tenantFeature = TenantFeature::firstOrNew(['feature_id' => $feature->id]);
        $tenantFeature->is_enabled = $request->boolean('enabled');

        if ($tenantFeature->is_enabled && !$tenantFeature->enabled_at) {
            $tenantFeature->enabled_at = now();
        }

        $tenantFeature->save();

        // Invalidate the canonical entitlement cache so the new state is visible immediately.
        $tenantId = $this->currentTenantId();
        if ($tenantId !== null) {
            $this->featureGate->invalidate($tenantId);
        }

        return response()->json([
            'message' => $tenantFeature->is_enabled ? 'Feature enabled' : 'Feature disabled',
            'enabled' => $tenantFeature->is_enabled,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatFeature(Feature $feature, bool $enabled): array
    {
        return [
            'id'          => $feature->id,
            'name'        => $feature->name,
            'key'         => $feature->key,
            'description' => $feature->description,
            'is_core'     => $feature->is_core,
            'enabled'     => $enabled,
        ];
    }

    /**
     * Resolve the current tenant ID from the tenancy runtime.
     * Returns null when no tenant context is active (CLI, tests, central routes).
     */
    private function currentTenantId(): ?string
    {
        if (!function_exists('tenant') || !tenant()) {
            return null;
        }

        return (string) tenant('id');
    }
}
