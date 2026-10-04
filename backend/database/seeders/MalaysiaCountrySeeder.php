<?php

namespace Database\Seeders;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeder for Malaysia (MY) country plugin.
 *
 * Seeds:
 *  1. Chart of Accounts — from "Account (account.account).xlsx" for My Malaysian Company
 *     Accounts are tagged with country_code = 'MY' and only appear when tenant is MY.
 *  2. Tax rates — Malaysia SST (Sales & Service Tax) rates.
 *
 * Run via CountrySetupSeeder when tenant_country_code = 'MY',
 * or directly: php artisan db:seed --class=MalaysiaCountrySeeder
 */
class MalaysiaCountrySeeder extends Seeder
{
    /**
     * Type mapping from Odoo/Excel account types → NoovaERP type + subtype
     * Based on chart_of_accounts expanded subtypes migration.
     */
    private array $typeMap = [
        'Fixed Assets' => ['type' => 'asset',     'subtype' => 'fixed_asset'],
        'Non-current Assets' => ['type' => 'asset',     'subtype' => 'asset_non_current'],
        'Current Assets' => ['type' => 'asset',     'subtype' => 'asset_current'],
        'Bank and Cash' => ['type' => 'asset',     'subtype' => 'asset_cash'],
        'Receivable' => ['type' => 'asset',     'subtype' => 'asset_receivable'],
        'Payable' => ['type' => 'liability', 'subtype' => 'liability_payable'],
        'Non-current Liabilities' => ['type' => 'liability', 'subtype' => 'liability_non_current'],
        'Current Liabilities' => ['type' => 'liability', 'subtype' => 'liability_current'],
        'Income' => ['type' => 'revenue',   'subtype' => 'income'],
        'Other Income' => ['type' => 'revenue',   'subtype' => 'income_other'],
        'Expenses' => ['type' => 'expense',   'subtype' => 'expense'],
        'Current Year Earnings' => ['type' => 'equity',    'subtype' => 'equity'],
        // #762 A2: equity accounts and equity distributions.
        'Equity' => ['type' => 'equity',    'subtype' => 'equity'],
    ];

    public function run(): void
    {
        $this->ensureCountryCodeColumn();
        $this->seedChartOfAccounts();
        $this->seedAccountMappings();
        $this->seedTaxRates();
        // #486: contribute MY payment rails (DuitNow/FPX/GrabPay) to the master.
        $this->callWith(PaymentMethodRailsSeeder::class, ['countryCode' => 'MY']);
    }

    /**
     * #763 (A3): the template wires its own accounts to the posting roles, so
     * the generic auto-seeded 1100/2200/4100 accounts never win. Roles the
     * template has no account for stay unmapped and are surfaced by the
     * account-mapping health check / resolve() setup error.
     */
    private const ACCOUNT_ROLE_MAPPINGS = [
        'cash' => '121001',
        'bank' => '120001',
        'accounts_receivable' => '124000',
        'accounts_payable' => '221100',
        'sales_tax_payable' => '221300',
        'sales_revenue' => '410000',
        'cogs' => '510100',
        'general_expense' => '520000',
    ];

    private function seedAccountMappings(): void
    {
        foreach (self::ACCOUNT_ROLE_MAPPINGS as $role => $code) {
            $account = DB::table('chart_of_accounts')->where('code', $code)->first();

            if (! $account) {
                continue;
            }

            DB::table('account_mappings')->updateOrInsert(
                ['mapping_key' => $role, 'document_type' => null],
                [
                    'account_id' => $account->id,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        \App\Models\Setting::setValue('accounting.coa_template', 'MY');
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
        // All 87 accounts from Account (account.account).xlsx — My Malaysian Company
        $accounts = [
            ['code' => '110000', 'name' => 'Fixed Assets',                          'excel_type' => 'Fixed Assets'],
            ['code' => '111000', 'name' => 'Furniture and Fixtures',                'excel_type' => 'Non-current Assets'],
            ['code' => '111220', 'name' => 'Liquidity Transfer',                    'excel_type' => 'Current Assets'],
            ['code' => '113000', 'name' => 'Equipment',                             'excel_type' => 'Non-current Assets'],
            ['code' => '114000', 'name' => 'Decoration',                            'excel_type' => 'Non-current Assets'],
            ['code' => '116000', 'name' => 'Investments',                           'excel_type' => 'Non-current Assets'],
            ['code' => '120000', 'name' => 'Current Assets',                        'excel_type' => 'Current Assets'],
            ['code' => '120001', 'name' => 'Bank',                                  'excel_type' => 'Bank and Cash'],
            ['code' => '120002', 'name' => 'Bank Suspense Account',                 'excel_type' => 'Current Assets'],
            ['code' => '120003', 'name' => 'Outstanding Receipts',                  'excel_type' => 'Current Assets'],
            ['code' => '120004', 'name' => 'Outstanding Payments',                  'excel_type' => 'Current Assets'],
            ['code' => '121001', 'name' => 'Cash',                                  'excel_type' => 'Bank and Cash'],
            ['code' => '123456', 'name' => 'Account Payslip Houserental',           'excel_type' => 'Payable'],
            ['code' => '124000', 'name' => 'Account Receivable',                    'excel_type' => 'Receivable'],
            ['code' => '124100', 'name' => 'Utility & Rental Deposit',              'excel_type' => 'Current Assets'],
            ['code' => '124200', 'name' => 'Supplier Prepayments',                  'excel_type' => 'Current Assets'],
            ['code' => '124300', 'name' => 'Account Receivable (PoS)',              'excel_type' => 'Receivable'],
            ['code' => '124500', 'name' => 'Sundry Deposits',                       'excel_type' => 'Current Assets'],
            ['code' => '124600', 'name' => 'Other Receivable',                      'excel_type' => 'Receivable'],
            ['code' => '125000', 'name' => 'Stock Interim Account (Received)',      'excel_type' => 'Current Assets'],
            ['code' => '126000', 'name' => 'Stock Interim Account (Delivered)',     'excel_type' => 'Current Assets'],
            ['code' => '127000', 'name' => 'Stock Valuation Account',               'excel_type' => 'Current Assets'],
            ['code' => '210000', 'name' => 'Non-current Liabilities',               'excel_type' => 'Non-current Liabilities'],
            ['code' => '220000', 'name' => 'Current Liabilities',                   'excel_type' => 'Current Liabilities'],
            ['code' => '221000', 'name' => 'Accruals',                              'excel_type' => 'Current Liabilities'],
            ['code' => '221010', 'name' => 'Accruals - KWSP',                       'excel_type' => 'Current Liabilities'],
            ['code' => '221020', 'name' => 'Accruals - PCB',                        'excel_type' => 'Current Liabilities'],
            ['code' => '221030', 'name' => 'Accruals - SOCSO',                      'excel_type' => 'Current Liabilities'],
            ['code' => '221040', 'name' => 'Accruals - EIS',                        'excel_type' => 'Current Liabilities'],
            ['code' => '221100', 'name' => 'Account Payable',                       'excel_type' => 'Payable'],
            ['code' => '221200', 'name' => 'Receipt in Advance (Customer Prepayments)', 'excel_type' => 'Current Liabilities'],
            ['code' => '221300', 'name' => 'SST Control Account',                   'excel_type' => 'Current Liabilities'],
            ['code' => '221400', 'name' => 'Provision for Taxation',                'excel_type' => 'Current Liabilities'],
            // #762 A2: dividends declared after the reporting date are not liabilities (MFRS 110).
            ['code' => '221500', 'name' => 'Proposed Dividend',                     'excel_type' => 'Equity'],
            ['code' => '221600', 'name' => 'Other Payable',                         'excel_type' => 'Payable'],
            ['code' => '310000', 'name' => 'Paid Capital',                          'excel_type' => 'Equity'],
            ['code' => '320000', 'name' => 'Accumulated Profit & Loss',             'excel_type' => 'Equity'],
            ['code' => '330000', 'name' => 'Profit & Loss Account',                 'excel_type' => 'Equity'],
            // #762 A2: a borrowing, numbered in the liability range rather than the equity block.
            ['code' => '240000', 'name' => 'Short-term Borrowing',                  'excel_type' => 'Current Liabilities'],
            ['code' => '410000', 'name' => 'Trade Income',                          'excel_type' => 'Income'],
            ['code' => '420000', 'name' => 'Other Income and Gains',                'excel_type' => 'Income'],
            ['code' => '421000', 'name' => 'Sundry Income',                         'excel_type' => 'Income'],
            ['code' => '422000', 'name' => 'Exchange Adjustment',                   'excel_type' => 'Income'],
            ['code' => '423000', 'name' => 'Bank Interest Income',                  'excel_type' => 'Income'],
            ['code' => '424000', 'name' => 'Foreign Exchange Gain',                 'excel_type' => 'Income'],
            ['code' => '510000', 'name' => 'Costs',                                 'excel_type' => 'Expenses'],
            ['code' => '510100', 'name' => 'Trade Costs',                           'excel_type' => 'Expenses'],
            ['code' => '510500', 'name' => 'Misc. Costs',                           'excel_type' => 'Expenses'],
            ['code' => '510600', 'name' => 'Costs of Transportation',               'excel_type' => 'Expenses'],
            ['code' => '511100', 'name' => 'Declaration Fees',                      'excel_type' => 'Expenses'],
            ['code' => '511200', 'name' => 'Packing Fees',                          'excel_type' => 'Expenses'],
            ['code' => '520000', 'name' => 'Expenses',                              'excel_type' => 'Expenses'],
            ['code' => '520100', 'name' => 'Bank Charges',                          'excel_type' => 'Expenses'],
            ['code' => '520200', 'name' => 'Entertainment',                         'excel_type' => 'Expenses'],
            ['code' => '520300', 'name' => 'Electricity & Water Fees',              'excel_type' => 'Expenses'],
            ['code' => '520500', 'name' => 'Postage & Stamps',                      'excel_type' => 'Expenses'],
            ['code' => '520600', 'name' => 'Printing & Stationery',                 'excel_type' => 'Expenses'],
            ['code' => '520700', 'name' => 'Rent & Rates',                          'excel_type' => 'Expenses'],
            ['code' => '520800', 'name' => 'Sundry Expenses',                       'excel_type' => 'Expenses'],
            ['code' => '520900', 'name' => 'Telecommunication Expenses',            'excel_type' => 'Expenses'],
            ['code' => '521000', 'name' => 'Traffic Fees',                          'excel_type' => 'Expenses'],
            ['code' => '521100', 'name' => 'IT Expenses',                           'excel_type' => 'Expenses'],
            ['code' => '521400', 'name' => 'Insurance',                             'excel_type' => 'Expenses'],
            ['code' => '521500', 'name' => 'Sales Commission',                      'excel_type' => 'Expenses'],
            ['code' => '521600', 'name' => 'Overseas Traveling',                    'excel_type' => 'Expenses'],
            ['code' => '521800', 'name' => 'Wages & Salaries',                      'excel_type' => 'Expenses'],
            ['code' => '521810', 'name' => 'KWSP Contribution',                     'excel_type' => 'Expenses'],
            // #762 A2: PCB is employee tax withheld — a liability accrual, not an employer cost.
            ['code' => '521820', 'name' => 'PCB Contribution',                      'excel_type' => 'Current Liabilities'],
            ['code' => '521830', 'name' => 'EIS Contribution',                      'excel_type' => 'Expenses'],
            ['code' => '521900', 'name' => 'Bonus Payment',                         'excel_type' => 'Expenses'],
            ['code' => '522100', 'name' => 'SST',                                   'excel_type' => 'Expenses'],
            ['code' => '522200', 'name' => 'Local Delivery',                        'excel_type' => 'Expenses'],
            ['code' => '522300', 'name' => 'Management Fees',                       'excel_type' => 'Expenses'],
            ['code' => '522400', 'name' => 'Depreciation',                          'excel_type' => 'Expenses'],
            ['code' => '522500', 'name' => 'Audit Fees',                            'excel_type' => 'Expenses'],
            ['code' => '522600', 'name' => 'Bad Debts',                             'excel_type' => 'Expenses'],
            ['code' => '522800', 'name' => 'Legal & Professional Fees',             'excel_type' => 'Expenses'],
            ['code' => '522900', 'name' => 'Dividend',                              'excel_type' => 'Equity'],
            ['code' => '523100', 'name' => 'Disposal',                              'excel_type' => 'Expenses'],
            ['code' => '523400', 'name' => 'Repair and Maintenance',                'excel_type' => 'Expenses'],
            ['code' => '523500', 'name' => 'Advertising',                           'excel_type' => 'Expenses'],
            ['code' => '524000', 'name' => 'Foreign Exchange Loss',                 'excel_type' => 'Expenses'],
            ['code' => '999001', 'name' => 'Cash Difference Gain',                  'excel_type' => 'Other Income'],
            ['code' => '999002', 'name' => 'Cash Difference Loss',                  'excel_type' => 'Expenses'],
            ['code' => '999997', 'name' => 'Cash Discount Gain',                    'excel_type' => 'Other Income'],
            ['code' => '999998', 'name' => 'Cash Discount Loss',                    'excel_type' => 'Expenses'],
            ['code' => '999999', 'name' => 'Undistributed Profits/Losses',          'excel_type' => 'Current Year Earnings'],
        ];

        $inserted = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $mapping = $this->typeMap[$account['excel_type']] ?? ['type' => 'asset', 'subtype' => 'current_asset'];

            // Skip if code already exists (idempotent)
            if (DB::table('chart_of_accounts')->where('code', $account['code'])->exists()) {
                $skipped++;

                continue;
            }

            DB::table('chart_of_accounts')->insert([
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $mapping['type'],
                'subtype' => $mapping['subtype'],
                'country_code' => 'MY',
                'is_active' => true,
                'is_system' => false,
                'description' => 'Malaysia (MFRS) — '.$account['excel_type'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        $this->command?->info("MY CoA: {$inserted} accounts inserted, {$skipped} skipped (already exist).");
    }

    private function seedTaxRates(): void
    {
        // Find the SST Control Account seeded above as the tax account
        $taxAccount = DB::table('chart_of_accounts')
            ->where('code', '221300') // SST Control Account
            ->first();

        if (! $taxAccount) {
            // Fallback to any liability account
            $taxAccount = DB::table('chart_of_accounts')
                ->where('type', 'liability')
                ->first();
        }

        if (! $taxAccount) {
            $this->command?->warn('MY Tax: No liability account found — skipping MY tax rate seeding.');

            return;
        }

        // #771 A11: tax rules come from the tax pack (#733/#739). This seeder
        // no longer writes the retired tax_rates table, which created a third
        // tax store with wrong types (service tax as sales) and unsourced WHT
        // rates that MigrateLegacyTaxRates could copy into the live engine.
        $this->command?->info('MY tax rates: skipped — tax rules come from the tax pack.');
    }
}
