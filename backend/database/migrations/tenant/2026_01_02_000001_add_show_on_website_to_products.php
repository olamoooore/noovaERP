<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add show_on_website flag to products table.
     * This allows filtering products that should be visible on the website/e-commerce.
     */
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }
        if (Schema::hasColumn('products', 'show_on_website')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('show_on_website')->default(false)->after('is_new_arrival');
            $table->index('show_on_website');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['show_on_website']);
            $table->dropColumn('show_on_website');
        });
    }
};
