<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Schema\Blueprint;
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
        $this->command?->info('SG CoA cleanup: removed ' . count($candidates) . ' unused SG-prefixed accounts.');
    }

    private function seedTaxRates(): void
    {
        // Use GST Output Tax Payable as the tax account
        $taxAccount = DB::table('chart_of_accounts')
            ->where('code', 'SG-2020') // GST Output Tax Payable
            ->first();

        if (!$taxAccount) {
            $taxAccount = DB::table('chart_of_accounts')
                ->where('type', 'liability')
                ->first();
        }

        if (!$taxAccount) {
            $this->command?->warn('SG Tax: No liability account found — skipping SG tax rate seeding.');
            return;
        }

        $sgTaxRates = [
            [
                'name'        => 'GST Standard Rated 9%',
                'code'        => 'SG-GST9',
                'rate'        => 9.00,
                'type'        => 'gst',
                'description' => 'Singapore GST — standard rated supply at 9% (effective 1 Jan 2024)',
            ],
            [
                'name'        => 'GST Zero Rated 0%',
                'code'        => 'SG-GST0',
                'rate'        => 0.00,
                'type'        => 'gst',
                'description' => 'Singapore GST — zero-rated supply (exports, international services)',
            ],
            [
                'name'        => 'GST Exempt',
                'code'        => 'SG-GSTEX',
                'rate'        => 0.00,
                'type'        => 'gst',
                'description' => 'Singapore GST — exempt supply (financial services, residential leasing)',
            ],
            [
                'name'        => 'GST Out of Scope',
                'code'        => 'SG-GSTOS',
                'rate'        => 0.00,
                'type'        => 'gst',
                'description' => 'Singapore GST — out-of-scope supply (third country sales, private transactions)',
            ],
            [
                'name'        => 'Withholding Tax 15% (SG)',
                'code'        => 'SG-WHT15',
                'rate'        => 15.00,
                'type'        => 'withholding',
                'description' => 'Singapore Withholding Tax — 15% on payments to non-residents (Section 45)',
            ],
            [
                'name'        => 'Withholding Tax 10% (SG)',
                'code'        => 'SG-WHT10',
                'rate'        => 10.00,
                'type'        => 'withholding',
                'description' => 'Singapore Withholding Tax — 10% on management fees to non-residents',
            ],
        ];

        $inserted = 0;
        $skipped  = 0;

        foreach ($sgTaxRates as $rate) {
            if (DB::table('tax_rates')->where('code', $rate['code'])->exists()) {
                $skipped++;
                continue;
            }

            DB::table('tax_rates')->insert(array_merge($rate, [
                'tax_account_id' => $taxAccount->id,
                'is_compound'    => false,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]));
            $inserted++;
        }

        $this->command?->info("SG Tax Rates: {$inserted} inserted, {$skipped} skipped.");
    }
}
