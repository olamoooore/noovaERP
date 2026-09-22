<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #131 (HIGH): batch consumption ledger.
 *
 * ProductBatch tracks per-batch quantity and cost, but no sale flow consumed
 * batches and nothing recorded which layer a sale drew from — so returns could
 * not restore layers and batch COGS was unverifiable. This ledger records each
 * FEFO consumption (movement + batch + quantity + cost snapshot) and is the
 * basis for reversible returns and the reconciliation command.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('batch_consumptions')) {
            return;
        }

        Schema::create('batch_consumptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_movement_id')->nullable()->index();
            $table->unsignedBigInteger('batch_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamps();

            // One ledger row per (movement/idempotency key, batch layer) —
            // retries must not double-consume a layer.
            $table->unique(['idempotency_key', 'batch_id'], 'batch_consumptions_key_batch_unique');

            $table->foreign('batch_id')
                ->references('id')->on('product_batches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_consumptions');
    }
};
