<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('discovered_devices', function (Blueprint $table) {
            // Increase port column size to accommodate long Windows HID paths
            $table->string('port', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovered_devices', function (Blueprint $table) {
            $table->string('port', 50)->nullable()->change();
        });
    }
};
