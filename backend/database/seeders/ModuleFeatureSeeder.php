<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\TenantFeature;
use App\Modules\ModuleManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ModuleFeatureSeeder extends Seeder
{
    public function run(): void
    {
        /** @var ModuleManager $modules */
        $modules = app(ModuleManager::class);

        $allModules = $modules->all();
        $moduleCount = count($allModules);
        
        \Log::info('[ModuleFeatureSeeder] Starting seeding process', [
            'total_modules' => $moduleCount,
            'module_keys' => array_map(fn($m) => $m->key, $allModules),
        ]);

        $seededCount = 0;
        $errors = [];

        foreach ($allModules as $module) {
            try {
                \Log::debug('[ModuleFeatureSeeder] Seeding module', [
                    'key' => $module->key,
                    'name' => $module->name,
                    'is_core' => $module->isCore(),
                ]);

                $feature = Feature::query()->updateOrCreate(
                    ['key' => $module->key],
                    [
                        'name' => $module->name,
                        'description' => $module->name.' module',
                        'is_core' => $module->isCore(),
                        'price' => $module->isCore() ? 0 : 0,
                    ]
                );

                \Log::info('[ModuleFeatureSeeder] Successfully seeded module', [
                    'key' => $module->key,
                    'feature_id' => $feature->id,
                    'was_recently_created' => $feature->wasRecentlyCreated,
                ]);

                $seededCount++;
            } catch (\Exception $e) {
                $errors[] = [
                    'key' => $module->key,
                    'error' => $e->getMessage(),
                ];
                
                \Log::error('[ModuleFeatureSeeder] Failed to seed module', [
                    'key' => $module->key,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        \Log::info('[ModuleFeatureSeeder] Seeding complete', [
            'total_modules' => $moduleCount,
            'seeded_count' => $seededCount,
            'error_count' => count($errors),
            'errors' => $errors,
        ]);
    }
}
