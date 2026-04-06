<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\HRM\Database\Seeders\HRMPermissionSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seeders = [
            ModuleFeatureSeeder::class,
        ];

        if (function_exists('tenant') && tenant()) {
            $seeders[] = HRMPermissionSeeder::class;
        }

        $this->call($seeders);
    }
}
