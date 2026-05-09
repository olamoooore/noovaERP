<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices') || Schema::hasColumn('invoices', 'shipping_method')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('shipping_method')->nullable()->after('shipping_cost');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'shipping_method')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('shipping_method');
        });
    }
};