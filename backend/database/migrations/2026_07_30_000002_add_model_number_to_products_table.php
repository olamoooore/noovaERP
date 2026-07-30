<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'model_number')) {
                $table->string('model_number', 100)->nullable()->after('secondary_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'model_number')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('model_number');
        });
    }
};
