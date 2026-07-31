<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->string('patient_name')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->dropColumn('patient_name');
        });
    }
};
