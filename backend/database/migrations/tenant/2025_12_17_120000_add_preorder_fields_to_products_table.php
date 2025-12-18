<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'is_preorder')) {
                $table->boolean('is_preorder')->default(false)->after('track_inventory');
                $table->index('is_preorder');
            }

            if (!Schema::hasColumn('products', 'preorder_available_at')) {
                $table->date('preorder_available_at')->nullable()->after('is_preorder');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'preorder_available_at')) {
                $table->dropColumn('preorder_available_at');
            }

            if (Schema::hasColumn('products', 'is_preorder')) {
                $table->dropIndex(['is_preorder']);
                $table->dropColumn('is_preorder');
            }
        });
    }
};
