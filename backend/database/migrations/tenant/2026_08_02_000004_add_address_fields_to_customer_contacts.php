<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->string('street')->nullable()->after('mobile');
            $table->string('street2')->nullable()->after('street');
            $table->string('city', 100)->nullable()->after('street2');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('zip', 20)->nullable()->after('state');
            $table->string('country', 100)->nullable()->after('zip');
        });
    }

    public function down(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->dropColumn(['street', 'street2', 'city', 'state', 'zip', 'country']);
        });
    }
};
