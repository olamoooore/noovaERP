<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->dropColumn(['level_unit', 'road_number', 'building_name', 'state', 'country', 'zip_code']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->string('level_unit', 50)->nullable()->after('mobile');
            $table->string('road_number', 50)->nullable()->after('level_unit');
            $table->string('building_name', 200)->nullable()->after('road_number');
            $table->string('state', 100)->nullable()->after('building_name');
            $table->string('country', 100)->nullable()->after('state');
            $table->string('zip_code', 20)->nullable()->after('country');
        });
    }
};
