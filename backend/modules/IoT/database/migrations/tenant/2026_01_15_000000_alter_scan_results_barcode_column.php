<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_results', function (Blueprint $table) {
            // Change barcode column from string(255) to text to support long QR/palm vein data
            $table->text('barcode')->change();
        });
    }

    public function down(): void
    {
        Schema::table('scan_results', function (Blueprint $table) {
            // Revert to string(255)
            $table->string('barcode')->change();
        });
    }
};
