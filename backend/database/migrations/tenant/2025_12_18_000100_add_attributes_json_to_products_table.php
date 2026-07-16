<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'attributes')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('attributes')->nullable()->after('gallery');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'attributes')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('attributes');
            });
        }
    }
};
