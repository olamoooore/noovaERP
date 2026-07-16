<?php

namespace Database\Seeders;

use App\Modules\ModuleManager;
use App\SuperAdmin\Application\AdminAuditLogger;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanVersion;
use Illuminate\Database\Seeder;

class ModuleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Sync all module.json manifests into module_catalog
        $syncService = new ModuleCatalogSyncService(
            moduleManager: app(ModuleManager::class),
            auditLogger: app(AdminAuditLogger::class),
        );

        $result = $syncService->sync();
        $this->command->info("Synced {$result['synced']} modules into module_catalog.");

        // 2. Seed 3 plan families with one version each
        $plans = [
            [
                'key' => 'trial',
                'name' => 'Trial',
                'description' => 'Free trial — full access for 14 days.',
                'sort_order' => 0,
            ],
            [
                'key' => 'starter',
                'name' => 'Starter',
                'description' => 'Essential modules for small businesses.',
                'sort_order' => 1,
            ],
            [
                'key' => 'professional',
                'name' => 'Professional',
                'description' => 'Full platform access with advanced modules.',
                'sort_order' => 2,
            ],
        ];

        foreach ($plans as $planData) {
            $family = PlanFamily::updateOrCreate(
                ['key' => $planData['key']],
                array_merge($planData, ['is_active' => true]),
            );

            PlanVersion::updateOrCreate(
                ['plan_family_id' => $family->id, 'version_label' => 'v1'],
                [
                    'is_active' => true,
                    'is_publicly_listed' => true,
                ],
            );

            $this->command->info("Seeded plan family: {$family->name}");
        }
    }
}
