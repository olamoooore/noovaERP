<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\EntitlementService;
use App\SuperAdmin\Application\TenantConfigurationService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\EntitlementRebuildJob;
use App\SuperAdmin\Domain\TenantSelectedItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminTenantConfigurationController
{
    public function __construct(
        private readonly EntitlementService          $entitlements,
        private readonly TenantConfigurationService  $config,
    ) {}

    /**
     * GET /tenants/{tenantId}/configuration
     * Full configuration view: tier, defaults, selections, entitlements, recent jobs.
     */
    public function show(string $tenantId): JsonResponse
    {
        $view = $this->entitlements->getConfigurationView($tenantId);

        return response()->json(['data' => $view]);
    }

    /**
     * GET /tenants/{tenantId}/entitlements
     * Resolved entitlement list — what the tenant can actually access.
     */
    public function entitlements(string $tenantId): JsonResponse
    {
        return response()->json([
            'data' => $this->entitlements->getEntitlements($tenantId),
        ]);
    }

    /**
     * GET /tenants/{tenantId}/entitlements/check?key=accounting.payables.ocr
     * Single key check — is this tenant entitled to this catalog item?
     */
    public function checkEntitlement(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string',
        ]);

        return response()->json([
            'data' => [
                'tenant_id'   => $tenantId,
                'key'         => $data['key'],
                'is_entitled' => $this->entitlements->isEntitled($tenantId, $data['key']),
            ],
        ]);
    }

    /**
     * POST /tenants/{tenantId}/configuration/selections
     * Add or update an explicit selection (add-on, override include/exclude).
     */
    public function addSelection(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'catalog_item_id' => 'required|integer|exists:catalog_items,id',
            'selection_type'  => 'required|in:' . implode(',', TenantSelectedItem::TYPES),
            'notes'           => 'nullable|string|max:500',
        ]);

        $item = CatalogItem::findOrFail($data['catalog_item_id']);

        try {
            $selection = $this->config->addSelection(
                $tenantId,
                $item,
                $data['selection_type'],
                $data['notes'] ?? null,
                Auth::id(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $selection], 201);
    }

    /**
     * DELETE /tenants/{tenantId}/configuration/selections/{item}
     * Remove an explicit selection. Item reverts to tier defaults.
     */
    public function removeSelection(string $tenantId, CatalogItem $item): JsonResponse
    {
        try {
            $this->config->removeSelection($tenantId, $item, Auth::id());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Selection not found.'], 404);
        }

        return response()->json(null, 204);
    }

    /**
     * POST /tenants/{tenantId}/configuration/reset
     * Remove all explicit selections and rebuild from tier defaults only.
     */
    public function reset(string $tenantId): JsonResponse
    {
        $this->config->resetToTierDefaults($tenantId, Auth::id());

        return response()->json(['data' => ['message' => 'Configuration reset to tier defaults.']]);
    }

    /**
     * POST /tenants/{tenantId}/entitlements/rebuild
     * Trigger a manual entitlement rebuild for this tenant.
     */
    public function rebuild(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:200',
        ]);

        $job = $this->entitlements->rebuild(
            $tenantId,
            Auth::id(),
            $data['reason'] ?? 'manual_rebuild',
        );

        return response()->json(['data' => $job], 201);
    }

    /**
     * GET /tenants/{tenantId}/entitlements/jobs
     * Recent rebuild job history for this tenant.
     */
    public function rebuildJobs(string $tenantId): JsonResponse
    {
        $jobs = EntitlementRebuildJob::forTenant($tenantId)
            ->recent(20)
            ->get();

        return response()->json(['data' => $jobs]);
    }
}
