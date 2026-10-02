<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-ARC-06 (P6 Close): remove the archived Ecommerce module from the
 * entitlement catalog.
 *
 * The module stays on disk (discovery + retained read surface untouched —
 * FR-ARC-03), but it must no longer be grantable/purchasable:
 *   - `module_catalog` row (legacy registry) + its dependency rows removed;
 *   - `catalog_items` row removed; `tenant_entitlements` rows for it cascade
 *     away (FK is cascadeOnDelete) — historical entitlement grants for an
 *     archived module have no meaning and gate nothing (the retained read
 *     routes are not entitlement-gated).
 *
 * Attached catalog rows (dependencies, incompatibilities, visibility rules,
 * prices, price rules) are removed first because their FKs to catalog_items
 * are NO ACTION.
 *
 * ModuleCatalogSyncService additionally skips `deprecated` modules so this
 * row cannot come back on the next sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        $itemIds = DB::table('catalog_items')->where('key', 'ecommerce')->pluck('id')->all();

        if ($itemIds !== []) {
            foreach ([
                'catalog_dependencies' => ['item_id', 'depends_on_item_id'],
                'catalog_incompatibilities' => ['item_id', 'incompatible_item_id'],
                'catalog_visibility_rules' => ['item_id'],
                'catalog_price_rules' => ['catalog_item_id'],
                'catalog_prices' => ['catalog_item_id'],
            ] as $table => $columns) {
                foreach ($columns as $column) {
                    DB::table($table)->whereIn($column, $itemIds)->delete();
                }
            }

            DB::table('catalog_items')->whereIn('id', $itemIds)->delete();
        }

        $moduleIds = DB::table('module_catalog')->where('module_key', 'ecommerce')->pluck('id')->all();

        if ($moduleIds !== []) {
            DB::table('module_dependencies')
                ->whereIn('module_catalog_id', $moduleIds)
                ->orWhereIn('depends_on_module_catalog_id', $moduleIds)
                ->delete();

            DB::table('module_catalog')->whereIn('id', $moduleIds)->delete();
        }
    }

    /**
     * Honest partial rollback: the module_catalog row is re-created from its
     * manifest truth so the next catalog sync / operator action can re-enable
     * the module if ever needed. Deleted catalog rows, attached rules and
     * cascaded entitlements are NOT restored — the archive decision stands
     * unless an operator explicitly re-onsboards the module.
     */
    public function down(): void
    {
        if (DB::table('module_catalog')->where('module_key', 'ecommerce')->doesntExist()) {
            DB::table('module_catalog')->insert([
                'module_key' => 'ecommerce',
                'name' => 'Website',
                'type' => 'addon',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
