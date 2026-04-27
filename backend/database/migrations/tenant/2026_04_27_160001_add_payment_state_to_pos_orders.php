<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->string('payment_state')->nullable()->after('status')
                ->comment('Payment state for customer display: processing, completed, failed');
            $table->string('payment_method')->nullable()->after('payment_state');
            $table->decimal('amount_tendered', 15, 2)->nullable()->after('payment_method');
            $table->decimal('change_due', 15, 2)->nullable()->after('amount_tendered');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_state', 'payment_method', 'amount_tendered', 'change_due']);
        });
    }
};

