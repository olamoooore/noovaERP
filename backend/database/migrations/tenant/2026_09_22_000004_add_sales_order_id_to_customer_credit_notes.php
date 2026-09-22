<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #154: credit notes issued from a sales order must carry the document
 * lineage back to that order (SalesOrder → Invoice → CreditNote), not just the
 * invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_credit_notes') || Schema::hasColumn('customer_credit_notes', 'sales_order_id')) {
            return;
        }

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('invoice_id')
                ->constrained('sales_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_credit_notes') || ! Schema::hasColumn('customer_credit_notes', 'sales_order_id')) {
            return;
        }

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_id');
        });
    }
};
