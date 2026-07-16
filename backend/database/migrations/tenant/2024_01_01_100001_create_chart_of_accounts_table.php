<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant: Chart of Accounts (COA)
     * Foundation of double-entry accounting
     * Account types: Asset, Liability, Equity, Revenue, Expense
     */
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // 1000, 1100, 2000, etc.
            $table->string('name'); // Cash, Accounts Receivable, Sales Revenue
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->enum('subtype', [
                'current_asset', 'fixed_asset', 'other_asset',
                'current_liability', 'long_term_liability',
                'equity',
                'operating_revenue', 'other_revenue',
                'cost_of_goods_sold', 'operating_expense', 'other_expense',
            ]);
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->onDelete('restrict');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // System accounts can't be deleted
            $table->decimal('opening_balance', 20, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('is_active');
        });

        // Seed standard chart of accounts
        $this->seedChartOfAccounts();
    }

    protected function seedChartOfAccounts(): void
    {
        $accounts = [
            // Assets
            ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'subtype' => 'current_asset', 'is_system' => DB::raw('true')],
            ['code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'is_system' => DB::raw('true')],
            ['code' => '1020', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'current_asset', 'is_system' => DB::raw('true')],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'current_asset', 'is_system' => DB::raw('true')],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'is_system' => DB::raw('true')],
            ['code' => '1500', 'name' => 'Fixed Assets', 'type' => 'asset', 'subtype' => 'fixed_asset', 'is_system' => DB::raw('true')],

            // Liabilities
            ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'subtype' => 'current_liability', 'is_system' => DB::raw('true')],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'is_system' => DB::raw('true')],
            ['code' => '2200', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'is_system' => DB::raw('true')],

            // Equity
            ['code' => '3000', 'name' => 'Equity', 'type' => 'equity', 'subtype' => 'equity', 'is_system' => DB::raw('true')],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'equity', 'is_system' => DB::raw('true')],

            // Revenue
            ['code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_system' => DB::raw('true')],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_system' => DB::raw('true')],

            // Expenses
            ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_system' => DB::raw('true')],
            ['code' => '5100', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold', 'is_system' => DB::raw('true')],
            ['code' => '5200', 'name' => 'Operating Expenses', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_system' => DB::raw('true')],
        ];

        foreach ($accounts as $account) {
            DB::table('chart_of_accounts')->insert(array_merge($account, [
                'is_system' => DB::raw('true'),
                'is_active' => DB::raw('true'),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
