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

        foreach ($modules->all() as $module) {
            $feature = Feature::query()->updateOrCreate(
                ['key' => $module->key],
                [
                    'name' => $module->name,
                    'description' => $module->name.' module',
                    'is_core' => $module->isCore(),
                    'price' => $module->isCore() ? 0 : 0,
                ]
            );

            if ($module->isCore()) {
                TenantFeature::query()->firstOrCreate(
                    ['feature_id' => $feature->id],
                    ['is_enabled' => true, 'enabled_at' => now()]
                );
            }
        }
    }
}
