<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\Services\SearchPathGuard;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\EntitlementRebuildJob;
use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanItemDefault;
use App\SuperAdmin\Domain\Subscription;
use App\SuperAdmin\Domain\TenantEntitlement;
use App\SuperAdmin\Domain\TenantSelectedItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    // =========================================================================
    // Read
    // =========================================================================

    /**
     * Current resolved entitlements for a tenant.
     * Reads the pre-built snapshot — fast, flat, no joins needed at runtime.
     */
    public function getEntitlements(string $tenantId): array
    {
        return $this->onCentralSchema(function () use ($tenantId) {
            return TenantEntitlement::with('catalogItem.type')
                ->forTenant($tenantId)
                ->orderBy('is_entitled', 'desc')
                ->get()
                ->toArray();
        });
    }

    /**
     * Check if a tenant is entitled to a specific catalog item key.
     * This is the canAccess() primitive used by ERP module gates.
     */
    public function isEntitled(string $tenantId, string $catalogItemKey): bool
    {
        return $this->onCentralSchema(function () use ($tenantId, $catalogItemKey) {
            $item = CatalogItem::where('key', $catalogItemKey)->first();
            if (! $item) {
                return false;
            }

            $entitlement = TenantEntitlement::forTenant($tenantId)
                ->where('catalog_item_id', $item->id)
                ->first();

            return $entitlement?->is_entitled ?? false;
        });
    }

    /**
     * Full configuration view for one tenant:
     * - active tier (plan family)
     * - all tier defaults for that tier
     * - all explicit selections
     * - resolved entitlements
     */
    public function getConfigurationView(string $tenantId): array
    {
        return $this->onCentralSchema(function () use ($tenantId) {
            $tier = $this->resolveActiveTier($tenantId);

            $tierDefaults = $tier
                ? PlanItemDefault::with('catalogItem.type')
                    ->where('plan_family_id', $tier->id)
                    ->get()
                    ->keyBy('catalog_item_id')
                : collect();

            $selections = TenantSelectedItem::with('catalogItem.type')
                ->forTenant($tenantId)
                ->get()
                ->keyBy('catalog_item_id');

            $entitlements = TenantEntitlement::with('catalogItem.type')
                ->forTenant($tenantId)
                ->get()
                ->keyBy('catalog_item_id');

            $allItems = CatalogItem::with('type')
                ->active()
                ->effective()
                ->ordered()
                ->get();

            $recentJobs = EntitlementRebuildJob::forTenant($tenantId)
                ->recent(5)
                ->get();

            return [
                'tenant_id' => $tenantId,
                'active_tier' => $tier,
                'items' => $allItems,
                'tier_defaults' => $tierDefaults,
                'selections' => $selections,
                'entitlements' => $entitlements,
                'recent_jobs' => $recentJobs,
            ];
        });
    }

    // =========================================================================
    // Rebuild
    // =========================================================================

    /**
     * Rebuild entitlements for a single tenant.
     *
     * Resolution order:
     *   1. All 'included' items from tier matrix (plan_item_defaults)
     *   2. Add add_on_purchased from tenant_selected_items
     *   3. Add override_include from tenant_selected_items
     *   4. Remove (mark blocked) override_exclude from tenant_selected_items
     *
     * Writes results atomically to tenant_entitlements.
     * Returns the rebuild job record.
     */
    public function rebuild(string $tenantId, ?int $adminId = null, ?string $reason = null): EntitlementRebuildJob
    {
        return $this->onCentralSchema(function () use ($tenantId, $adminId, $reason) {
            $job = EntitlementRebuildJob::create([
                'tenant_id' => $tenantId,
                'status' => EntitlementRebuildJob::STATUS_PENDING,
                'reason' => $reason ?? 'manual_rebuild',
                'triggered_by_admin_id' => $adminId,
            ]);

            try {
                $job->markRunning();

                $resolved = $this->resolveEntitlementsInner($tenantId);
                $itemCount = count($resolved);

                DB::transaction(function () use ($tenantId, $resolved) {
                    TenantEntitlement::forTenant($tenantId)->delete();

                    $now = now();
                    $rows = array_map(fn ($row) => array_merge($row, [
                        'resolved_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]), $resolved);

                    foreach (array_chunk($rows, 200) as $chunk) {
                        TenantEntitlement::insert($chunk);
                    }
                });

                $job->markCompleted($itemCount);

                Cache::forget("feature_gate:{$tenantId}");

                $this->audit->log(
                    'entitlements.rebuilt',
                    null,
                    [],
                    ['tenant_id' => $tenantId, 'items_resolved' => $itemCount, 'reason' => $reason],
                );
            } catch (\Throwable $e) {
                $job->markFailed($e->getMessage());
                throw $e;
            }

            return $job->fresh();
        });
    }

    // =========================================================================
    // Private — Resolution Logic
    // =========================================================================

    /**
     * Core resolution algorithm.
     * Returns array of rows ready for tenant_entitlements insert.
     */
    private function resolveEntitlementsInner(string $tenantId): array
    {
        $tier = $this->resolveActiveTier($tenantId);

        // Keyed by catalog_item_id: ['is_entitled' => bool, 'source' => string]
        $resolved = [];

        // Step 1 — Tier included items
        if ($tier) {
            $includedDefaults = PlanItemDefault::where('plan_family_id', $tier->id)
                ->where('availability', PlanItemDefault::AVAILABILITY_INCLUDED)
                ->get();

            foreach ($includedDefaults as $default) {
                $resolved[$default->catalog_item_id] = [
                    'tenant_id' => $tenantId,
                    'catalog_item_id' => $default->catalog_item_id,
                    'is_entitled' => true,
                    'source' => TenantEntitlement::SOURCE_TIER_INCLUDED,
                ];
            }
        }

        // Step 2 — Add-on purchased
        $selections = TenantSelectedItem::forTenant($tenantId)->get();

        foreach ($selections as $selection) {
            if ($selection->selection_type === TenantSelectedItem::TYPE_ADD_ON_PURCHASED) {
                $resolved[$selection->catalog_item_id] = [
                    'tenant_id' => $tenantId,
                    'catalog_item_id' => $selection->catalog_item_id,
                    'is_entitled' => true,
                    'source' => TenantEntitlement::SOURCE_ADD_ON_PURCHASED,
                ];
            }
        }

        // Step 3 — Override includes (admin-granted)
        foreach ($selections as $selection) {
            if ($selection->selection_type === TenantSelectedItem::TYPE_OVERRIDE_INCLUDE) {
                $resolved[$selection->catalog_item_id] = [
                    'tenant_id' => $tenantId,
                    'catalog_item_id' => $selection->catalog_item_id,
                    'is_entitled' => true,
                    'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE,
                ];
            }
        }

        // Step 4 — Override excludes (admin-blocked, highest priority)
        foreach ($selections as $selection) {
            if ($selection->selection_type === TenantSelectedItem::TYPE_OVERRIDE_EXCLUDE) {
                $resolved[$selection->catalog_item_id] = [
                    'tenant_id' => $tenantId,
                    'catalog_item_id' => $selection->catalog_item_id,
                    'is_entitled' => false,
                    'source' => TenantEntitlement::SOURCE_OVERRIDE_EXCLUDE,
                ];
            }
        }

        return array_values($resolved);
    }

    /**
     * Resolve the active plan tier for a tenant from their active subscription.
     * Returns null if no active subscription exists.
     */
    public function resolveActiveTier(string $tenantId): ?PlanFamily
    {
        return $this->onCentralSchema(function () use ($tenantId) {
            $subscription = Subscription::where('tenant_id', $tenantId)
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->latest()
                ->first();

            if (! $subscription) {
                return null;
            }

            return PlanFamily::whereHas('versions', function ($q) use ($subscription) {
                $q->where('id', $subscription->plan_version_id);
            })->first();
        });
    }

    private function onCentralSchema(callable $callback): mixed
    {
        $inTenantContext = function_exists('tenant') && tenant();

        if ($inTenantContext) {
            $previousPath = DB::connection()->getPdo()->query('SHOW search_path')->fetchColumn();
            SearchPathGuard::setSearchPath('public', 'Accessing central entitlement data');
        }

        try {
            return $callback();
        } finally {
            if ($inTenantContext && isset($previousPath)) {
                SearchPathGuard::resetSearchPath();
            }
        }
    }
}
