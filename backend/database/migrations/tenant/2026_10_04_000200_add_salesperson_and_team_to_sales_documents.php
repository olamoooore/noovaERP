<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #553 (SALES-ODOO): default sales team on sales documents. Quotations
 * already carry the free-text salesperson (#321); this adds the sales team
 * alongside it and gives sales orders the same pair, so the creation defaults
 * configured in Sales Settings have a column to land in.
 *
 * Additive only: both columns are nullable, so existing rows keep the current
 * (empty) behaviour unless a tenant configures defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('sales_team', 255)->nullable()->after('salesperson');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('salesperson', 255)->nullable()->after('contact_phone');
            $table->string('sales_team', 255)->nullable()->after('salesperson');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('sales_team');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['salesperson', 'sales_team']);
        });
    }
};
