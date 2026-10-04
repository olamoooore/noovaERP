<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #408 (SALES-ODOO): commitment (promised) date and shipping policy on
 * quotations and sales orders. The expected date is not stored — it is
 * computed from the order date plus the product/supplier lead time (see
 * App\Services\SalesLeadTimeService) and exposed as a model accessor.
 *
 * Additive only: nullable date + column default, so existing rows keep the
 * permissive 'asap' policy and a null promise date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->date('commitment_date')->nullable()->after('required_date');
            $table->string('shipping_policy', 32)->default('asap')->after('commitment_date');
            $table->index('commitment_date');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->date('commitment_date')->nullable()->after('valid_until');
            $table->string('shipping_policy', 32)->default('asap')->after('commitment_date');
            $table->index('commitment_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['commitment_date']);
            $table->dropColumn(['commitment_date', 'shipping_policy']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['commitment_date']);
            $table->dropColumn(['commitment_date', 'shipping_policy']);
        });
    }
};
