<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BankAccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Find asset accounts for bank
        $cashAccount = DB::table('chart_of_accounts')
            ->where('type', 'asset')
            ->where('name', 'LIKE', '%Cash%')
            ->first();

        $bankAccount = DB::table('chart_of_accounts')
            ->where('type', 'asset')
            ->where('name', 'LIKE', '%Bank%')
            ->first();

        if (!$cashAccount && !$bankAccount) {
            // Use any asset account
            $cashAccount = DB::table('chart_of_accounts')
                ->where('type', 'asset')
                ->first();
        }

        if (!$cashAccount) {
            $this->command->error('No asset account found. Please create asset accounts first.');
            return;
        }

        $glAccountId = $bankAccount ? $bankAccount->id : $cashAccount->id;
        $this->command->info("Using GL account: " . ($bankAccount ? $bankAccount->name : $cashAccount->name) . " (ID: {$glAccountId})");

        // Clear existing bank accounts
        DB::table('bank_accounts')->truncate();

        // Sample bank accounts (matching actual table structure)
        $bankAccounts = [
            [
                'account_name' => 'Main Business Checking',
                'account_number' => '1234567890',
                'bank_name' => 'Bank of America',
                'currency_code' => 'USD',
                'gl_account_id' => $glAccountId,
                'is_active' => true,
            ],
            [
                'account_name' => 'Business Savings',
                'account_number' => '9876543210',
                'bank_name' => 'Chase Bank',
                'currency_code' => 'USD',
                'gl_account_id' => $glAccountId,
                'is_active' => true,
            ],
            [
                'account_name' => 'Payroll Account',
                'account_number' => '5555666677',
                'bank_name' => 'Wells Fargo',
                'currency_code' => 'USD',
                'gl_account_id' => $glAccountId,
                'is_active' => true,
            ],
            [
                'account_name' => 'Business Credit Card',
                'account_number' => '4111111111111111',
                'bank_name' => 'American Express',
                'currency_code' => 'USD',
                'gl_account_id' => $glAccountId,
                'is_active' => true,
            ],
        ];

        foreach ($bankAccounts as $account) {
            DB::table('bank_accounts')->insert(array_merge($account, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command->info('Successfully seeded ' . count($bankAccounts) . ' bank accounts!');
    }
}

