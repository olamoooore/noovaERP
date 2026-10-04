<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #333 ([SALES-ODOO]) — Incoterms on sales documents and tenant cash
 * rounding on invoices.
 *
 * Incoterms are additive nullable free text on quotations, sales orders and
 * invoices: code (e.g. FOB) + place (e.g. Port Klang). No statutory code list
 * is baked in — validation is length-bounded free text (see the controllers)
 * so tenants are never locked out of terms the platform has not enumerated.
 *
 * rounding_amount is the signed cash-rounding delta applied to the invoice's
 * final gross total; the invoice total is already the rounded figure. It is
 * persisted so the posting path and the printed document read the exact same
 * number the totals calculation produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('incoterm_code', 20)->nullable()->after('shipping_terms');
            $table->string('incoterm_place', 255)->nullable()->after('incoterm_code');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('incoterm_code', 20)->nullable()->after('shipping_policy');
            $table->string('incoterm_place', 255)->nullable()->after('incoterm_code');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('incoterm_code', 20)->nullable()->after('shipping_method');
            $table->string('incoterm_place', 255)->nullable()->after('incoterm_code');
            $table->decimal('rounding_amount', 14, 2)->default(0)->after('shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['incoterm_code', 'incoterm_place', 'rounding_amount']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['incoterm_code', 'incoterm_place']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['incoterm_code', 'incoterm_place']);
        });
    }
};
