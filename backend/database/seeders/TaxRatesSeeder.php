<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxRatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Find the Sales Tax Payable account (liability)
        $taxAccount = DB::table('chart_of_accounts')
            ->where('type', 'liability')
            ->where('name', 'LIKE', '%Tax%')
            ->first();

        if (!$taxAccount) {
            // If no tax account exists, use any liability account
            $taxAccount = DB::table('chart_of_accounts')
                ->where('type', 'liability')
                ->first();
        }

        if (!$taxAccount) {
            $this->command->error('No liability account found. Please create a liability account first.');
            return;
        }

        $this->command->info("Using tax account: {$taxAccount->name} (ID: {$taxAccount->id})");

        // Clear existing tax rates
        DB::table('tax_rates')->truncate();

        // Common tax rates
        $taxRates = [
            [
                'name' => 'Sales Tax 10%',
                'code' => 'ST10',
                'rate' => 10.00,
                'type' => 'sales',
                'tax_account_id' => $taxAccount->id,
                'is_compound' => false,
                'is_active' => true,
                'description' => 'Standard sales tax rate of 10%',
            ],
            [
                'name' => 'VAT 15%',
                'code' => 'VAT15',
                'rate' => 15.00,
                'type' => 'vat',
                'tax_account_id' => $taxAccount->id,
                'is_compound' => false,
                'is_active' => true,
                'description' => 'Value Added Tax at 15%',
            ],
            [
                'name' => 'GST 6%',
                'code' => 'GST6',
                'rate' => 6.00,
                'type' => 'gst',
                'tax_account_id' => $taxAccount->id,
                'is_compound' => false,
                'is_active' => true,
                'description' => 'Goods and Services Tax at 6%',
            ],
            [
                'name' => 'Purchase Tax 5%',
                'code' => 'PT5',
                'rate' => 5.00,
                'type' => 'purchase',
                'tax_account_id' => $taxAccount->id,
                'is_compound' => false,
                'is_active' => true,
                'description' => 'Tax on purchases from vendors',
            ],
            [
                'name' => 'Withholding Tax 2%',
                'code' => 'WHT2',
                'rate' => 2.00,
                'type' => 'withholding',
                'tax_account_id' => $taxAccount->id,
                'is_compound' => false,
                'is_active' => true,
                'description' => 'Withholding tax on payments',
            ],
            [
                'name' => 'Sales Tax 5%',
                'code' => 'ST5',
                'rate' => 5.00,
                'type' => 'sales',
                'tax_account_id' => $taxAccount->id,
                'is_compound' => false,
                'is_active' => true,
                'description' => 'Reduced sales tax rate of 5%',
            ],
            [
                'name' => 'VAT 20%',
                'code' => 'VAT20',
                'rate' => 20.00,
                'type' => 'vat',
                'tax_account_id' => $taxAccount->id,
                'is_compound' => false,
                'is_active' => true,
                'description' => 'Standard VAT rate at 20%',
            ],
            [
                'name' => 'Zero Rated',
                'code' => 'ZERO',
                'rate' => 0.00,
                'type' => 'sales',
                'tax_account_id' => $taxAccount->id,
                'is_compound' => false,
                'is_active' => true,
                'description' => 'Zero-rated tax for exports and exempt items',
            ],
        ];

        foreach ($taxRates as $taxRate) {
            DB::table('tax_rates')->insert(array_merge($taxRate, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command->info('Successfully seeded ' . count($taxRates) . ' tax rates!');
    }
}

