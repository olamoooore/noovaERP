<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Http\Request;

/**
 * FeatureController — lists and toggles features for the current tenant.
 *
 * Entitlement checks now read from `tenant_entitlements` via FeatureGateService
 * (the canonical platform entitlement snapshot) rather than the legacy
 * `tenant_features` table, which lacks tenant identity and is being retired.
 *
 */
class FeatureController extends Controller
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {}

    public function index()
    {
        $tenantId = $this->currentTenantId();

        $entitledKeys = $tenantId
            ? collect($this->featureGate->all($tenantId))->pluck('key')->flip()
            : collect();

        // Features catalog is central (public schema), not tenant-scoped
        $features = \DB::table('public.features')->get()->map(function ($feature) use ($entitledKeys, $tenantId) {
            if ($feature->is_core) {
                return $this->formatFeatureFromRow($feature, true);
            }

            if ($tenantId !== null) {
                return $this->formatFeatureFromRow($feature, $entitledKeys->has($feature->key));
            }

            return $this->formatFeatureFromRow($feature, false);
        });

        return response()->json($features);
    }

    public function toggle(Request $request, $id)
    {
        $feature = \DB::table('public.features')->where('id', $id)->first();

        if (!$feature) {
            return response()->json(['message' => 'Feature not found'], 404);
        }

        if ($feature->is_core) {
            return response()->json(['message' => 'Cannot disable core features'], 403);
        }

        $tenantId = $this->currentTenantId();
        if ($tenantId === null) {
            return response()->json(['message' => 'No tenant context available'], 403);
        }

        return response()->json([
            'message' => 'Feature entitlement changes must be managed through plan configuration.',
        ], 403);
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

    private function formatFeatureFromRow(object $feature, bool $enabled): array
    {
        return [
            'id'          => $feature->id,
            'name'        => $feature->name,
            'key'         => $feature->key,
            'description' => $feature->description ?? '',
            'is_core'     => (bool) $feature->is_core,
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
