<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'road_number',
                'road_name',
                'level_unit',
                'building_name',
                'state',
                'zip_code',
                'country',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('road_number', 50)->nullable()->after('shipping_address');
            $table->string('road_name', 200)->nullable()->after('road_number');
            $table->string('level_unit', 50)->nullable()->after('road_name');
            $table->string('building_name', 200)->nullable()->after('level_unit');
            $table->string('state', 100)->nullable()->after('building_name');
            $table->string('zip_code', 20)->nullable()->after('state');
            $table->string('country', 100)->nullable()->after('zip_code');
        });
    }
};
