<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->string('lot_number')->nullable()->after('unit');
            $table->string('batch_number')->nullable()->after('lot_number');
            $table->date('expiry_date')->nullable()->after('batch_number');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->dropColumn(['lot_number', 'batch_number', 'expiry_date']);
        });
    }
};
