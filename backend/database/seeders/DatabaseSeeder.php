<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\HRM\Database\Seeders\HRMPermissionSeeder;
use Modules\Loyalty\Database\Seeders\LoyaltyTierSeeder;
use Modules\TemplateBuilder\Services\RegistryService;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seeders = [
            ModuleFeatureSeeder::class,
        ];

        if (function_exists('tenant') && tenant()) {
            $seeders[] = PaymentMethodsSeeder::class; // #486 generic method master
            $seeders[] = DepartmentLocationSeeder::class;
            $seeders[] = HRMPermissionSeeder::class;
            $seeders[] = AccountingPermissionSeeder::class;
            $seeders[] = LoyaltyTierSeeder::class;
            $seeders[] = FiscalPeriodSeeder::class; // Seed fiscal periods for accounting
            // Issue #66: AssuranceGate needs baseline blocking policies to
            // enforce; provisioned per-tenant into the central policy table.
            $seeders[] = AssuranceBlockingPolicySeeder::class;

            // ADR-004: seed canonical registrations before reference templates.
            app(RegistryService::class)->seedCanonical();

            $seeders[] = ReferenceTemplateSeeder::class;
        }

        $this->call($seeders);
    }
}
