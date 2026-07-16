<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Country Setup Seeder — dispatcher.
 *
 * Checks the TENANT_COUNTRY_CODE environment variable (or falls back to
 * seeding both countries) and calls the appropriate country seeder.
 *
 * Usage:
 *   # Seed for a specific country (from artisan command context):
 *   php artisan db:seed --class=CountrySetupSeeder
 *
 *   # With explicit country:
 *   TENANT_COUNTRY_CODE=MY php artisan db:seed --class=CountrySetupSeeder
 *   TENANT_COUNTRY_CODE=SG php artisan db:seed --class=CountrySetupSeeder
 *
 * Called automatically from DatabaseSeeder for tenant provisioning.
 */
class CountrySetupSeeder extends Seeder
{
    public function run(): void
    {
        $countryCode = strtoupper(
            env('TENANT_COUNTRY_CODE', '') ?:
            $this->getCountryFromTenantContext()
        );

        if ($countryCode === 'MY') {
            $this->command?->info('CountrySetupSeeder: Seeding Malaysia (MY) accounts and tax rates.');
            $this->call(MalaysiaCountrySeeder::class);
        } elseif ($countryCode === 'SG') {
            $this->command?->info('CountrySetupSeeder: Seeding Singapore (SG) accounts and tax rates.');
            $this->call(SingaporeCountrySeeder::class);
        } else {
            // No specific country — seed both so system is ready for either.
            $this->command?->info('CountrySetupSeeder: No specific country — seeding both SG and MY accounts.');
            $this->call(SingaporeCountrySeeder::class);
            $this->call(MalaysiaCountrySeeder::class);
        }
    }

    /**
     * Attempt to read country from tenancy context at seed time.
     * Returns empty string if not in a tenant context.
     */
    private function getCountryFromTenantContext(): string
    {
        try {
            $tenant = tenancy()->tenant ?? null;

            return strtoupper($tenant?->tenant_country_code ?? '');
        } catch (\Throwable) {
            return '';
        }
    }
}
