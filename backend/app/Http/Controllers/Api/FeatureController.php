<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\SuperAdmin\Application\FeatureGateService;
/**
 * FeatureController — lists features for the current tenant.
 *
 * Entitlement checks now read from `tenant_entitlements` via FeatureGateService
 * (the canonical platform entitlement snapshot) rather than the legacy
 * `tenant_features` table, which lacks tenant identity and is being retired.
 *
 * Tenant-facing module listings are read-only. Plan and entitlement changes must
 * be managed through the canonical control-plane configuration flow rather than
 * tenant self-service writes to either legacy or canonical state.
 */
class FeatureController extends Controller
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {}

    public function index()
    {
        $tenantId = $this->currentTenantId();

        if ($tenantId === null) {
            return response()->json(['message' => 'No tenant context available'], 403);
        }

        $entitledKeys = collect($this->featureGate->all($tenantId))->pluck('key')->flip();

        $features = Feature::all()->map(function (Feature $feature) use ($entitledKeys) {
            // Core features are always enabled regardless of entitlement record.
            if ($feature->is_core) {
                return $this->formatFeature($feature, true);
            }

            return $this->formatFeature($feature, $entitledKeys->has($feature->key));
        });

        return response()->json($features);
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
