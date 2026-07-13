<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('tenant_country_code', 2)->nullable()->after('name');
            $table->boolean('country_plugin_enabled')->default(false)->after('tenant_country_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['tenant_country_code', 'country_plugin_enabled']);
        });
    }
};
