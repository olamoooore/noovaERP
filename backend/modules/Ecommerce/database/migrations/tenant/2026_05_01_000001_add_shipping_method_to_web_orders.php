<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('web_orders') || Schema::hasColumn('web_orders', 'shipping_method')) {
            return;
        }

        Schema::table('web_orders', function (Blueprint $table) {
            $table->jsonb('shipping_method')->nullable()->after('shipping_address');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('web_orders') || !Schema::hasColumn('web_orders', 'shipping_method')) {
            return;
        }

        Schema::table('web_orders', function (Blueprint $table) {
            $table->dropColumn('shipping_method');
        });
    }
};
