<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'vendor_price')) {
            Schema::table('products', fn (Blueprint $table) => $table->decimal('vendor_price', 15, 2)->nullable()->after('cost'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'vendor_price')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('vendor_price'));
        }
    }
};
