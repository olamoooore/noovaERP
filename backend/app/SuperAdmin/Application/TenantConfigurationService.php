<?php

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\TenantSelectedItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TenantConfigurationService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AdminAuditLogger $audit,
    ) {}

    // =========================================================================
    // Read
    // =========================================================================

    public function getSelections(string $tenantId): Collection
    {
        return TenantSelectedItem::with('catalogItem.type')
            ->forTenant($tenantId)
            ->get();
    }

    // =========================================================================
    // Write
    // =========================================================================

    /**
     * Add or update a selection for a tenant.
     * Automatically rebuilds entitlements after the change.
     */
    public function addSelection(
        string $tenantId,
        CatalogItem $item,
        string $selectionType,
        ?string $notes = null,
        ?int $adminId = null,
    ): TenantSelectedItem {
        $this->validateSelectionType($selectionType);

        $before = TenantSelectedItem::where('tenant_id', $tenantId)
            ->where('catalog_item_id', $item->id)
            ->first()
            ?->toAuditSnapshot() ?? [];

        $selection = DB::transaction(function () use ($tenantId, $item, $selectionType, $notes, $adminId) {
            return TenantSelectedItem::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'catalog_item_id' => $item->id,
                ],
                [
                    'selection_type' => $selectionType,
                    'notes' => $notes,
                    'selected_by_admin_id' => $adminId,
                ],
            );
        });

        $this->audit->log(
            'tenant_config.selection_added',
            null,
            $before,
            $selection->toAuditSnapshot(),
        );

        // Rebuild entitlements to reflect the change immediately
        $this->entitlements->rebuild($tenantId, $adminId, "selection_added:{$item->key}");

        return $selection->load('catalogItem.type');
    }

    /**
     * Remove an explicit selection.
     * The item reverts to whatever the tier matrix dictates.
     * Automatically rebuilds entitlements after the change.
     */
    public function removeSelection(
        string $tenantId,
        CatalogItem $item,
        ?int $adminId = null,
    ): void {
        $selection = TenantSelectedItem::where('tenant_id', $tenantId)
            ->where('catalog_item_id', $item->id)
            ->firstOrFail();

        $snapshot = $selection->toAuditSnapshot();
        $selection->delete();

        $this->audit->log('tenant_config.selection_removed', null, $snapshot, []);

        $this->entitlements->rebuild($tenantId, $adminId, "selection_removed:{$item->key}");
    }

    /**
     * Remove all selections for a tenant and rebuild from tier defaults only.
     * Used when resetting a tenant's configuration to tier defaults.
     */
    public function resetToTierDefaults(string $tenantId, ?int $adminId = null): void
    {
        DB::transaction(function () use ($tenantId) {
            TenantSelectedItem::forTenant($tenantId)->delete();
        });

        $this->audit->log(
            'tenant_config.reset_to_defaults',
            null,
            ['tenant_id' => $tenantId],
            [],
        );

        $this->entitlements->rebuild($tenantId, $adminId, 'reset_to_tier_defaults');
    }

    // =========================================================================
    // Private
    // =========================================================================

    private function validateSelectionType(string $type): void
    {
        if (! in_array($type, TenantSelectedItem::TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid selection type \"{$type}\". Must be one of: ".
                implode(', ', TenantSelectedItem::TYPES),
            );
        }
    }
}
