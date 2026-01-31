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
            Feature::query()->updateOrCreate(
                ['key' => $module->key],
                [
                    'name' => $module->name,
                    'description' => $module->name.' module',
                    'is_core' => $module->isCore(),
                    'price' => $module->isCore() ? 0 : 0,
                ]
            );
        }
    }
}
