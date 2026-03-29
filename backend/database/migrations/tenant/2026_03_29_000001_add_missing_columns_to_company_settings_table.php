<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Company info fields (frontend sends these but DB lacked them)
            $table->string('tax_id', 50)->nullable()->after('company_logo_url');
            $table->text('address')->nullable()->after('tax_id');
            $table->string('phone', 30)->nullable()->after('address');
            $table->string('email')->nullable()->after('phone');
            $table->string('website', 500)->nullable()->after('email');

            // Fiscal year end + lock date (updateFiscalYear was silently dropping these)
            $table->date('fiscal_year_end')->nullable()->after('fiscal_year_start_day');
            $table->date('lock_date')->nullable()->after('fiscal_year_end');

            // Currency format fields (updateCurrencies was silently dropping these)
            $table->unsignedTinyInteger('decimal_places')->default(2)->after('number_format');
            $table->string('thousand_separator', 1)->default(',')->after('decimal_places');
            $table->string('decimal_separator', 1)->default('.')->after('thousand_separator');

            // Additional default account columns (frontend has 10 selectors, DB had 5)
            $table->foreignId('default_purchase_tax_account_id')->nullable()->after('default_tax_account_id')->constrained('chart_of_accounts');
            $table->foreignId('default_inventory_account_id')->nullable()->after('default_purchase_tax_account_id')->constrained('chart_of_accounts');
            $table->foreignId('default_cogs_account_id')->nullable()->after('default_inventory_account_id')->constrained('chart_of_accounts');
            $table->foreignId('default_retained_earnings_account_id')->nullable()->after('default_cogs_account_id')->constrained('chart_of_accounts');
            $table->foreignId('default_bank_charges_account_id')->nullable()->after('default_retained_earnings_account_id')->constrained('chart_of_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropForeign(['default_purchase_tax_account_id']);
            $table->dropForeign(['default_inventory_account_id']);
            $table->dropForeign(['default_cogs_account_id']);
            $table->dropForeign(['default_retained_earnings_account_id']);
            $table->dropForeign(['default_bank_charges_account_id']);

            $table->dropColumn([
                'tax_id', 'address', 'phone', 'email', 'website',
                'fiscal_year_end', 'lock_date',
                'decimal_places', 'thousand_separator', 'decimal_separator',
                'default_purchase_tax_account_id', 'default_inventory_account_id',
                'default_cogs_account_id', 'default_retained_earnings_account_id',
                'default_bank_charges_account_id',
            ]);
        });
    }
};
