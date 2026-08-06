<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add missing fields to invoices table
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('invoices', 'billing_address_snapshot')) {
                    $table->json('billing_address_snapshot')->nullable()->after('quotation_id');
                }
                if (!Schema::hasColumn('invoices', 'delivery_address_snapshot')) {
                    $table->json('delivery_address_snapshot')->nullable()->after('billing_address_snapshot');
                }
                if (!Schema::hasColumn('invoices', 'payment_terms_snapshot')) {
                    $table->string('payment_terms_snapshot')->nullable()->after('delivery_address_snapshot');
                }
            });
        }

        // Add missing fields to delivery_orders table
        if (Schema::hasTable('delivery_orders')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('delivery_orders', 'delivery_address_snapshot')) {
                    $table->json('delivery_address_snapshot')->nullable()->after('quotation_id');
                }
                if (!Schema::hasColumn('delivery_orders', 'delivered_by_user_id')) {
                    $table->foreignId('delivered_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('completed_by');
                }
                if (!Schema::hasColumn('delivery_orders', 'issued_at')) {
                    $table->timestamp('issued_at')->nullable()->after('delivered_by_user_id');
                }
                if (!Schema::hasColumn('delivery_orders', 'delivered_at')) {
                    $table->timestamp('delivered_at')->nullable()->after('issued_at');
                }
            });
        }
    }

    public function down(): void
    {
        // Remove fields from delivery_orders table
        if (Schema::hasTable('delivery_orders')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                if (Schema::hasColumn('delivery_orders', 'delivery_address_snapshot')) {
                    $table->dropColumn('delivery_address_snapshot');
                }
                if (Schema::hasColumn('delivery_orders', 'delivered_by_user_id')) {
                    $table->dropForeign(['delivered_by_user_id']);
                    $table->dropColumn('delivered_by_user_id');
                }
                if (Schema::hasColumn('delivery_orders', 'issued_at')) {
                    $table->dropColumn('issued_at');
                }
                if (Schema::hasColumn('delivery_orders', 'delivered_at')) {
                    $table->dropColumn('delivered_at');
                }
            });
        }

        // Remove fields from invoices table
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (Schema::hasColumn('invoices', 'billing_address_snapshot')) {
                    $table->dropColumn('billing_address_snapshot');
                }
                if (Schema::hasColumn('invoices', 'delivery_address_snapshot')) {
                    $table->dropColumn('delivery_address_snapshot');
                }
                if (Schema::hasColumn('invoices', 'payment_terms_snapshot')) {
                    $table->dropColumn('payment_terms_snapshot');
                }
            });
        }
    }
};
