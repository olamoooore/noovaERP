<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_settings', 'shipping_revenue_account_id')) {
                $table->unsignedBigInteger('shipping_revenue_account_id')->nullable()->after('default_revenue_account_id');
            }

            if (! Schema::hasColumn('company_settings', 'default_purchase_tax_account_id')) {
                $table->foreignId('default_purchase_tax_account_id')->nullable()->after('default_tax_account_id')->constrained('chart_of_accounts');
            }

            if (! Schema::hasColumn('company_settings', 'default_inventory_account_id')) {
                $table->foreignId('default_inventory_account_id')->nullable()->after('default_purchase_tax_account_id')->constrained('chart_of_accounts');
            }

            if (! Schema::hasColumn('company_settings', 'default_cogs_account_id')) {
                $table->foreignId('default_cogs_account_id')->nullable()->after('default_inventory_account_id')->constrained('chart_of_accounts');
            }

            if (! Schema::hasColumn('company_settings', 'default_retained_earnings_account_id')) {
                $table->foreignId('default_retained_earnings_account_id')->nullable()->after('default_cogs_account_id')->constrained('chart_of_accounts');
            }

            if (! Schema::hasColumn('company_settings', 'default_bank_charges_account_id')) {
                $table->foreignId('default_bank_charges_account_id')->nullable()->after('default_retained_earnings_account_id')->constrained('chart_of_accounts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $columns = [
                'shipping_revenue_account_id',
                'default_purchase_tax_account_id',
                'default_inventory_account_id',
                'default_cogs_account_id',
                'default_retained_earnings_account_id',
                'default_bank_charges_account_id',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('company_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
