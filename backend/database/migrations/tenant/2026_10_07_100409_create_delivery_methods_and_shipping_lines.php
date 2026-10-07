<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #409 ([SALES-ODOO]): delivery-method master + shipping lines.
 *
 * Additive only — one new table and new nullable/defaulted columns. Every
 * existing quotation/order line defaults to is_delivery = false and every
 * existing document to no delivery method, so no total changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->nullable()->unique();
            // fixed | percentage (of the order's untaxed amount).
            $table->string('pricing_type', 20)->default('fixed');
            // Fixed price in the tenant base currency.
            $table->decimal('fixed_price', 15, 4)->default(0);
            $table->decimal('percentage', 7, 4)->default(0);
            // Free when the order's untaxed amount (base currency) reaches it.
            $table->decimal('free_over_amount', 15, 2)->nullable();
            // The service product the charge is billed through (its tax and
            // income account come from the existing engines).
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('delivery_method_id')->nullable()->constrained('delivery_methods')->nullOnDelete();
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('delivery_method_id')->nullable()->constrained('delivery_methods')->nullOnDelete();
        });

        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->boolean('is_delivery')->default(false);
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->boolean('is_delivery')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('is_delivery');
        });

        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->dropColumn('is_delivery');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_method_id');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_method_id');
        });

        Schema::dropIfExists('delivery_methods');
    }
};
