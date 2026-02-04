<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add Jingxi-specific subtypes to chart_of_accounts
     */
    public function up(): void
    {
        // PostgreSQL requires dropping and recreating the enum type
        // First, add a temporary column
        DB::statement("ALTER TABLE chart_of_accounts ADD COLUMN subtype_new VARCHAR(50)");
        
        // Copy existing values
        DB::statement("UPDATE chart_of_accounts SET subtype_new = subtype");
        
        // Drop the old column
        DB::statement("ALTER TABLE chart_of_accounts DROP COLUMN subtype");
        
        // Recreate with expanded enum
        DB::statement("
            ALTER TABLE chart_of_accounts 
            ADD COLUMN subtype VARCHAR(50) CHECK (subtype IN (
                'current_asset', 'fixed_asset', 'other_asset',
                'asset_receivable', 'asset_current', 'asset_cash', 'asset_fixed', 'asset_non_current',
                'current_liability', 'long_term_liability',
                'liability_payable', 'liability_current', 'liability_non_current',
                'equity',
                'operating_revenue', 'other_revenue',
                'income', 'income_other',
                'cost_of_goods_sold', 'operating_expense', 'other_expense',
                'expense_direct_cost', 'expense', 'expense_depreciation'
            ))
        ");
        
        // Copy back the values
        DB::statement("UPDATE chart_of_accounts SET subtype = subtype_new");
        
        // Drop temporary column
        DB::statement("ALTER TABLE chart_of_accounts DROP COLUMN subtype_new");
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        // Revert to original enum values
        DB::statement("ALTER TABLE chart_of_accounts ADD COLUMN subtype_new VARCHAR(50)");
        
        // Map expanded types back to original types
        DB::statement("
            UPDATE chart_of_accounts 
            SET subtype_new = CASE 
                WHEN subtype IN ('asset_receivable', 'asset_current', 'asset_cash', 'current_asset') THEN 'current_asset'
                WHEN subtype IN ('asset_fixed', 'asset_non_current', 'fixed_asset') THEN 'fixed_asset'
                WHEN subtype = 'other_asset' THEN 'other_asset'
                WHEN subtype IN ('liability_payable', 'liability_current', 'current_liability') THEN 'current_liability'
                WHEN subtype IN ('liability_non_current', 'long_term_liability') THEN 'long_term_liability'
                WHEN subtype = 'equity' THEN 'equity'
                WHEN subtype IN ('income', 'operating_revenue') THEN 'operating_revenue'
                WHEN subtype IN ('income_other', 'other_revenue') THEN 'other_revenue'
                WHEN subtype IN ('expense_direct_cost', 'cost_of_goods_sold') THEN 'cost_of_goods_sold'
                WHEN subtype IN ('expense', 'expense_depreciation', 'operating_expense') THEN 'operating_expense'
                WHEN subtype = 'other_expense' THEN 'other_expense'
                ELSE 'current_asset'
            END
        ");
        
        DB::statement("ALTER TABLE chart_of_accounts DROP COLUMN subtype");
        
        DB::statement("
            ALTER TABLE chart_of_accounts 
            ADD COLUMN subtype VARCHAR(50) CHECK (subtype IN (
                'current_asset', 'fixed_asset', 'other_asset',
                'current_liability', 'long_term_liability',
                'equity',
                'operating_revenue', 'other_revenue',
                'cost_of_goods_sold', 'operating_expense', 'other_expense'
            ))
        ");
        
        DB::statement("UPDATE chart_of_accounts SET subtype = subtype_new");
        DB::statement("ALTER TABLE chart_of_accounts DROP COLUMN subtype_new");
    }
};
