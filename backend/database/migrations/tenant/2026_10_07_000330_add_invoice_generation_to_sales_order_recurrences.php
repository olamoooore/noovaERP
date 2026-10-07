<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #330: a Sales Order recurrence can generate invoices instead of
 * orders, optionally posting and sending them. Additive only — existing
 * schedules keep generating orders (document_type defaults to 'order').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_order_recurrences')) {
            return;
        }

        Schema::table('sales_order_recurrences', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_order_recurrences', 'document_type')) {
                $table->string('document_type', 20)->default('order'); // order|invoice
            }
            if (! Schema::hasColumn('sales_order_recurrences', 'auto_post')) {
                $table->boolean('auto_post')->default(false);
            }
            if (! Schema::hasColumn('sales_order_recurrences', 'auto_send')) {
                $table->boolean('auto_send')->default(false);
            }
            if (! Schema::hasColumn('sales_order_recurrences', 'generated_invoice_ids')) {
                $table->json('generated_invoice_ids')->nullable();
            }
            if (! Schema::hasColumn('sales_order_recurrences', 'last_error')) {
                $table->text('last_error')->nullable();
            }
            if (! Schema::hasColumn('sales_order_recurrences', 'last_error_at')) {
                $table->timestamp('last_error_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_order_recurrences')) {
            return;
        }

        Schema::table('sales_order_recurrences', function (Blueprint $table) {
            foreach (['document_type', 'auto_post', 'auto_send', 'generated_invoice_ids', 'last_error', 'last_error_at'] as $column) {
                if (Schema::hasColumn('sales_order_recurrences', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
