<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\HRM\Database\Seeders\HRMPermissionSeeder;
use Modules\Loyalty\Database\Seeders\LoyaltyTierSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seeders = [
            ModuleFeatureSeeder::class,
        ];

        if (function_exists('tenant') && tenant()) {
            $seeders[] = DepartmentLocationSeeder::class;
            $seeders[] = HRMPermissionSeeder::class;
            $seeders[] = LoyaltyTierSeeder::class;
        }

        $this->call($seeders);
    }
}
