<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_settings') || Schema::hasColumn('company_settings', 'shipping_revenue_account_id')) {
            return;
        }

        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('shipping_revenue_account_id')->nullable()->after('default_revenue_account_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('company_settings') || ! Schema::hasColumn('company_settings', 'shipping_revenue_account_id')) {
            return;
        }

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('shipping_revenue_account_id');
        });
    }
};
