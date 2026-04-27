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
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->after('reference_id');
            
            // Unique index on (customer_loyalty_id, idempotency_key) to prevent duplicates
            $table->unique(['customer_loyalty_id', 'idempotency_key'], 'loyalty_tx_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropUnique('loyalty_tx_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
