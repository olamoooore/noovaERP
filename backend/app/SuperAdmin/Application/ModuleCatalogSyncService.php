<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\Modules\ModuleManager;
use App\SuperAdmin\Domain\ModuleCatalogEntry;
use Illuminate\Support\Facades\DB;

class ModuleCatalogSyncService
{
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    /**
     * Sync module_catalog from module.json manifests.
     *
     * New modules are inserted; existing modules have their name and type updated.
     * The module_key is immutable — it is the identity column.
     * is_active and sort_order are never overwritten by sync so operators can manage them manually.
     *
     * @return array{synced: int, keys: string[]}
     */
    public function sync(): array
    {
        $modules = $this->moduleManager->all();
        $keys = [];

        DB::transaction(function () use ($modules, &$keys): void {
            foreach ($modules as $module) {
                // FR-ARC-06 (P6 close): archived modules never (re)enter the
                // entitlement catalog. ModuleManager discovery is untouched —
                // the archived module stays loadable for its retained read
                // surface; it just is not grantable/purchasable anymore.
                if (($module->deprecated ?? false) === true) {
                    continue;
                }

                ModuleCatalogEntry::upsert(
                    [[
                        'module_key' => $module->key,
                        'name' => $module->name,
                        'type' => $module->isCore() ? 'core' : 'addon',
                        'updated_at' => now(),
                    ]],
                    uniqueBy: ['module_key'],
                    update: ['name', 'type', 'updated_at'],
                );

                $keys[] = $module->key;
            }
        });

        $this->auditLogger->log(
            action: 'module_catalog.synced',
            target: null,
            before: [],
            after: ['synced_keys' => $keys],
        );

        return ['synced' => count($keys), 'keys' => $keys];
    }
}
