<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\TenantFeature;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\TenantConfigurationService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\TenantSelectedItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * FeatureController — lists and toggles features for the current tenant.
 *
 * Entitlement checks now read from `tenant_entitlements` via FeatureGateService
 * (the canonical platform entitlement snapshot) rather than the legacy
 * `tenant_features` table, which lacks tenant identity and is being retired.
 *
 * The `toggle()` action writes through to the canonical entitlement system
 * via TenantConfigurationService to keep both systems in sync during the migration period.
 */
class FeatureController extends Controller
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly TenantConfigurationService $configService,
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

        $enabled = $request->boolean('enabled');

        // Legacy: Update tenant_features table for backward compatibility
        $tenantFeature = TenantFeature::firstOrNew(['feature_id' => $feature->id]);
        $tenantFeature->is_enabled = $enabled;
        if ($enabled && !$tenantFeature->enabled_at) {
            $tenantFeature->enabled_at = now();
        }
        $tenantFeature->save();

        // Canonical: Sync to entitlement system via catalog item
        $this->syncToCanonicalEntitlements($tenantId, $feature, $enabled);

        // Invalidate the canonical entitlement cache
        $this->featureGate->invalidate($tenantId);

        return response()->json([
            'message' => $enabled ? 'Feature enabled' : 'Feature disabled',
            'enabled' => $enabled,
        ]);
    }

    private function syncToCanonicalEntitlements(string $tenantId, object $feature, bool $enabled): void
    {
        $catalogItem = CatalogItem::where('key', $feature->key)->first();

        if (!$catalogItem) {
            Log::warning('Feature toggle: No catalog item found for feature key', [
                'feature_id' => $feature->id,
                'feature_key' => $feature->key,
                'tenant_id' => $tenantId,
            ]);
            return;
        }

        $existingSelection = TenantSelectedItem::where('tenant_id', $tenantId)
            ->where('catalog_item_id', $catalogItem->id)
            ->first();

        if ($enabled) {
            if (!$existingSelection) {
                $this->configService->addSelection(
                    $tenantId,
                    $catalogItem,
                    TenantSelectedItem::TYPE_OVERRIDE_INCLUDE,
                    "Legacy feature toggle enabled: {$feature->key}",
                    null
                );
            }
        } else {
            if ($existingSelection) {
                $this->configService->removeSelection($tenantId, $catalogItem, null);
            }
        }
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
