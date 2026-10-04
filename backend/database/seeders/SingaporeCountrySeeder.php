<?php

namespace Database\Seeders;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeder for Singapore (SG) country plugin.
 *
 * Seeds:
 *  1. Chart of Accounts — SG-specific SFRS-aligned accounts tagged country_code = 'SG'.
 *     The existing generic CoA (1000–5200) stays universal (country_code = null).
 *     This seeder adds SG-specific supplementary accounts.
 *  2. Tax rates — Singapore GST rates (9% current rate, 0% for zero-rated exports).
 *
 * Run via CountrySetupSeeder when tenant_country_code = 'SG',
 * or directly: php artisan db:seed --class=SingaporeCountrySeeder
 */
class SingaporeCountrySeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureCountryCodeColumn();
        $this->cleanupLegacySingaporeCountryAccounts();
        $this->seedChartOfAccounts();
        $this->seedTaxRates();
        // #486: contribute SG payment rails (PayNow/GrabPay) to the master.
        $this->callWith(PaymentMethodRailsSeeder::class, ['countryCode' => 'SG']);
    }

    /**
     * Backward-safe guard for tenants where migration history is inconsistent.
     */
    private function ensureCountryCodeColumn(): void
    {
        if (Schema::hasColumn('chart_of_accounts', 'country_code')) {
            return;
        }

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('country_code', 2)
                ->nullable()
                ->comment('Country-specific account visibility. NULL = universal, SG/MY = country-only');
            $table->index('country_code');
        });
    }

    private function seedChartOfAccounts(): void
    {
        // SG mode uses the existing legacy CoA (country_code = null) by design.
        $this->command?->info('SG CoA: skipped (using existing legacy CoA list).');
    }

    /**
     * Remove old SG-prefixed CoA rows that were previously seeded, but only
     * when they have no journal usage.
     */
    private function cleanupLegacySingaporeCountryAccounts(): void
    {
        $candidates = DB::table('chart_of_accounts as coa')
            ->leftJoin('journal_entry_lines as jel', 'jel.account_id', '=', 'coa.id')
            ->where('coa.country_code', 'SG')
            ->where('coa.code', 'like', 'SG-%')
            ->whereNull('jel.id')
            ->select('coa.id')
            ->pluck('id')
            ->all();

        if (empty($candidates)) {
            return;
        }

        DB::table('chart_of_accounts')->whereIn('id', $candidates)->delete();
        $this->command?->info('SG CoA cleanup: removed '.count($candidates).' unused SG-prefixed accounts.');
    }

    private function seedTaxRates(): void
    {
        // Use GST Output Tax Payable as the tax account
        $taxAccount = DB::table('chart_of_accounts')
            ->where('code', 'SG-2020') // GST Output Tax Payable
            ->first();

        if (! $taxAccount) {
            $taxAccount = DB::table('chart_of_accounts')
                ->where('type', 'liability')
                ->first();
        }

        if (! $taxAccount) {
            $this->command?->warn('SG Tax: No liability account found — skipping SG tax rate seeding.');

            return;
        }

        // #771 A11: tax rules come from the tax pack (#733/#739). This seeder
        // no longer writes the retired tax_rates table, which created a third
        // tax store with unsourced WHT rates that MigrateLegacyTaxRates could
        // copy into the live engine.
        $this->command?->info('SG tax rates: skipped — tax rules come from the tax pack.');
    }
}
