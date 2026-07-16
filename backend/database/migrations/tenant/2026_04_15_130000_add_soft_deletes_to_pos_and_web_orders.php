<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_orders') && ! Schema::hasColumn('pos_orders', 'deleted_at')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('web_orders') && ! Schema::hasColumn('web_orders', 'deleted_at')) {
            Schema::table('web_orders', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_orders') && Schema::hasColumn('pos_orders', 'deleted_at')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('web_orders') && Schema::hasColumn('web_orders', 'deleted_at')) {
            Schema::table('web_orders', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
