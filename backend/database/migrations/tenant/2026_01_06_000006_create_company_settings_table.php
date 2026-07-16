<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('company_logo_url', 500)->nullable();
            $table->integer('fiscal_year_start_month')->default(1); // 1-12
            $table->integer('fiscal_year_start_day')->default(1);   // 1-31
            $table->string('base_currency', 3)->default('USD');
            $table->string('date_format', 20)->default('MM/DD/YYYY');
            $table->string('number_format', 20)->default('1,234.56');
            $table->string('timezone', 50)->default('UTC');

            // Default accounts
            $table->foreignId('default_receivable_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('default_payable_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('default_revenue_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('default_expense_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('default_tax_account_id')->nullable()->constrained('chart_of_accounts');

            $table->timestamps();

            $table->foreign('base_currency')->references('code')->on('currencies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
