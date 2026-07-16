<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AR-002: Add stable idempotency_key to stock_movements.
     *
     * Enables InventoryService::adjustStock() to deduplicate duplicate mutation
     * events from POS retries, eCommerce webhooks, GRN double-posts, etc.
     * Combined with the existing (reference_type, reference_id, product_id, type)
     * unique constraint this gives two deduplication layers:
     *   1. Caller-supplied idempotency_key (opaque, stable key from source system)
     *   2. Structural uniqueness on reference coordinates (migration 2026_04_27)
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements') || Schema::hasColumn('stock_movements', 'idempotency_key')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            // Nullable so existing rows are unaffected; unique so duplicates are caught at DB level.
            $table->string('idempotency_key', 128)->nullable()->unique()->after('reference_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements') || ! Schema::hasColumn('stock_movements', 'idempotency_key')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
