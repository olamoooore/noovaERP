<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            $table->unsignedBigInteger('void_approved_by')->nullable()->after('voided_by');
            $table->string('void_reason', 500)->nullable()->after('void_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn(['voided_by', 'void_approved_by', 'void_reason']);
        });
    }
};