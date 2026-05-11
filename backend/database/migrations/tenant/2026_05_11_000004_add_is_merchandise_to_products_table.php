<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'is_merchandise')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_merchandise')->default(false)->after('is_new_arrival');
            $table->index('is_merchandise');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'is_merchandise')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_merchandise']);
            $table->dropColumn('is_merchandise');
        });
    }
};