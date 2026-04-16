<?php

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\CatalogDependency;
use App\SuperAdmin\Domain\CatalogIncompatibility;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\CatalogVisibilityRule;
use App\SuperAdmin\Domain\ModuleCatalogEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CatalogService
{
    /**
     * Business key validation pattern.
     * Valid examples: "accounting", "accounting.payables", "accounting.payables.ocr"
     */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){0,2}$/';

    public function __construct(private readonly AdminAuditLogger $audit) {}

    // =========================================================================
    // Item Types
    // =========================================================================

    public function listTypes(): Collection
    {
        return CatalogItemType::ordered()->get();
    }

    public function createType(array $data): CatalogItemType
    {
        $type = CatalogItemType::create([
            'key'         => $data['key'],
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order'  => $data['sort_order'] ?? 0,
        ]);

        $this->audit->log('catalog_type.created', $type, [], $type->toArray());

        return $type;
    }

    // =========================================================================
    // Catalog Items
    // =========================================================================

    /**
     * Full tree for the admin UI: top-level items with nested children.
     * Filtered to active + currently effective items only.
     */
    public function listTree(): array
    {
        $items = CatalogItem::with(['type', 'children.type', 'children.children.type'])
            ->active()
            ->effective()
            ->topLevel()
            ->ordered()
            ->get();

        return $items->values()->all();
    }

    /**
     * Flat list — all items regardless of hierarchy, useful for dropdowns.
     */
    public function listFlat(): Collection
    {
        return CatalogItem::with('type')
            ->active()
            ->effective()
            ->ordered()
            ->get();
    }

    public function createItem(array $data): CatalogItem
    {
        $this->validateKey($data['key']);

        $item = DB::transaction(function () use ($data) {
            return CatalogItem::create([
                'key'            => $data['key'],
                'name'           => $data['name'],
                'description'    => $data['description'] ?? null,
                'type_id'        => $data['type_id'],
                'parent_id'      => $data['parent_id'] ?? null,
                'is_active'      => $data['is_active'] ?? true,
                'sort_order'     => $data['sort_order'] ?? 0,
                'metadata'       => $data['metadata'] ?? null,
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'effective_to'   => $data['effective_to'] ?? null,
                'source'         => 'catalog',
            ]);
        });

        $this->audit->log('catalog_item.created', $item, [], $item->toAuditSnapshot());

        return $item->load('type');
    }

    public function updateItem(CatalogItem $item, array $data): CatalogItem
    {
        $before = $item->toAuditSnapshot();

        // Key is permanently immutable — business contracts depend on it
        unset($data['key'], $data['source']);

        DB::transaction(function () use ($item, $data) {
            $item->update(array_filter([
                'name'           => $data['name']           ?? $item->name,
                'description'    => array_key_exists('description', $data) ? $data['description'] : $item->description,
                'type_id'        => $data['type_id']        ?? $item->type_id,
                'parent_id'      => array_key_exists('parent_id', $data) ? $data['parent_id'] : $item->parent_id,
                'is_active'      => $data['is_active']      ?? $item->is_active,
                'sort_order'     => $data['sort_order']     ?? $item->sort_order,
                'metadata'       => array_key_exists('metadata', $data) ? $data['metadata'] : $item->metadata,
                'effective_from' => $data['effective_from'] ?? $item->effective_from,
                'effective_to'   => array_key_exists('effective_to', $data) ? $data['effective_to'] : $item->effective_to,
            ], fn ($v) => $v !== null || array_key_exists('description', $data) || array_key_exists('metadata', $data) || array_key_exists('parent_id', $data) || array_key_exists('effective_to', $data)));
        });

        $item->refresh();
        $this->audit->log('catalog_item.updated', $item, $before, $item->toAuditSnapshot());

        return $item->load('type');
    }

    /**
     * Soft deactivation — sets effective_to to today and is_active = false.
     * Never hard-deletes catalog items: entitlement and pricing history depends on them.
     */
    public function deactivateItem(CatalogItem $item): CatalogItem
    {
        $before = $item->toAuditSnapshot();

        $item->update([
            'is_active'    => false,
            'effective_to' => now()->toDateString(),
        ]);

        $this->audit->log('catalog_item.deactivated', $item, $before, $item->toAuditSnapshot());

        return $item;
    }

    public function getItemByKey(string $key): ?CatalogItem
    {
        return CatalogItem::with(['type', 'dependencies.dependsOn', 'incompatibilities.incompatibleItem', 'visibilityRules'])
            ->where('key', $key)
            ->first();
    }

    // =========================================================================
    // Dependencies
    // =========================================================================

    public function addDependency(CatalogItem $item, CatalogItem $dependsOn, string $type = 'required'): CatalogDependency
    {
        $dep = CatalogDependency::firstOrCreate(
            ['item_id' => $item->id, 'depends_on_item_id' => $dependsOn->id],
            ['type'    => $type],
        );

        $this->audit->log(
            'catalog_dependency.added',
            $item,
            [],
            ['item_key' => $item->key, 'depends_on_key' => $dependsOn->key, 'type' => $type],
        );

        return $dep->load(['item', 'dependsOn']);
    }

    public function removeDependency(CatalogDependency $dependency): void
    {
        $snapshot = [
            'item_key'       => $dependency->item->key ?? $dependency->item_id,
            'depends_on_key' => $dependency->dependsOn->key ?? $dependency->depends_on_item_id,
        ];

        $dependency->delete();

        $this->audit->log('catalog_dependency.removed', null, $snapshot, []);
    }

    // =========================================================================
    // Incompatibilities
    // =========================================================================

    public function addIncompatibility(CatalogItem $item, CatalogItem $incompatibleWith, ?string $reason = null): CatalogIncompatibility
    {
        $incompat = CatalogIncompatibility::firstOrCreate(
            ['item_id' => $item->id, 'incompatible_item_id' => $incompatibleWith->id],
            ['reason'  => $reason],
        );

        $this->audit->log(
            'catalog_incompatibility.added',
            $item,
            [],
            ['item_key' => $item->key, 'incompatible_key' => $incompatibleWith->key],
        );

        return $incompat->load(['item', 'incompatibleItem']);
    }

    public function removeIncompatibility(CatalogIncompatibility $incompat): void
    {
        $snapshot = [
            'item_key'          => $incompat->item->key ?? $incompat->item_id,
            'incompatible_key'  => $incompat->incompatibleItem->key ?? $incompat->incompatible_item_id,
        ];

        $incompat->delete();

        $this->audit->log('catalog_incompatibility.removed', null, $snapshot, []);
    }

    // =========================================================================
    // Visibility Rules
    // =========================================================================

    public function addVisibilityRule(CatalogItem $item, string $ruleType, array $condition): CatalogVisibilityRule
    {
        $rule = CatalogVisibilityRule::create([
            'item_id'   => $item->id,
            'rule_type' => $ruleType,
            'condition' => $condition,
        ]);

        $this->audit->log(
            'catalog_visibility_rule.added',
            $item,
            [],
            ['item_key' => $item->key, 'rule_type' => $ruleType, 'condition' => $condition],
        );

        return $rule;
    }

    public function removeVisibilityRule(CatalogVisibilityRule $rule): void
    {
        $snapshot = ['item_id' => $rule->item_id, 'rule_type' => $rule->rule_type];

        $rule->delete();

        $this->audit->log('catalog_visibility_rule.removed', null, $snapshot, []);
    }

    // =========================================================================
    // Legacy Migration
    // =========================================================================

    /**
     * One-time idempotent sync from module_catalog → catalog_items.
     * Safe to run multiple times — skips items whose key already exists.
     * Sets source = 'legacy_module' on all imported rows.
     */
    public function syncFromLegacyModuleCatalog(): array
    {
        $moduleType = CatalogItemType::where('key', 'module')->firstOrFail();

        $legacyModules = ModuleCatalogEntry::all();

        $synced  = 0;
        $skipped = 0;
        $keys    = [];

        DB::transaction(function () use ($legacyModules, $moduleType, &$synced, &$skipped, &$keys) {
            foreach ($legacyModules as $module) {
                $exists = CatalogItem::where('key', $module->module_key)->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                CatalogItem::create([
                    'key'            => $module->module_key,
                    'name'           => $module->name,
                    'description'    => $module->description,
                    'type_id'        => $moduleType->id,
                    'parent_id'      => null,
                    'is_active'      => $module->is_active,
                    'sort_order'     => $module->sort_order,
                    'metadata'       => null,
                    'effective_from' => now()->toDateString(),
                    'effective_to'   => null,
                    'source'         => 'legacy_module',
                ]);

                $synced++;
                $keys[] = $module->module_key;
            }
        });

        $this->audit->log(
            'catalog.synced_from_legacy',
            null,
            [],
            ['synced' => $synced, 'skipped' => $skipped, 'keys' => $keys],
        );

        return ['synced' => $synced, 'skipped' => $skipped, 'keys' => $keys];
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function validateKey(string $key): void
    {
        if (! preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException(
                "Invalid catalog key \"{$key}\". Keys must follow the convention: " .
                "module[.submodule[.feature]] using lowercase letters, digits, and underscores only."
            );
        }
    }
}
