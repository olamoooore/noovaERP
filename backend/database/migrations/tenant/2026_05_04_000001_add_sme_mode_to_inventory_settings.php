<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_settings', 'sme_mode')) {
            Schema::table('inventory_settings', function (Blueprint $table) {
                $table->boolean('sme_mode')->default(false)->after('enable_barcode_scanning');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_settings', 'sme_mode')) {
            Schema::table('inventory_settings', function (Blueprint $table) {
                $table->dropColumn('sme_mode');
            });
        }
    }
};
