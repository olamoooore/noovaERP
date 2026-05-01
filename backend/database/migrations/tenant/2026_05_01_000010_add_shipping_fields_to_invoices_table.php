<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'shipping_cost')) {
                $table->decimal('shipping_cost', 10, 2)->nullable()->after('discount_amount');
            }

            if (!Schema::hasColumn('invoices', 'shipping_method')) {
                $table->string('shipping_method')->nullable()->after('shipping_cost');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'shipping_method')) {
                $table->dropColumn('shipping_method');
            }

            if (Schema::hasColumn('invoices', 'shipping_cost')) {
                $table->dropColumn('shipping_cost');
            }
        });
    }
};
