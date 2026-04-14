<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the stock_reservations table.
 *
 * Replaces the bare reserved_quantity counter on stock_levels with a
 * first-class lifecycle document. Each reservation is tied to a source
 * order line (sales order, web order, etc.) and transitions through a
 * clear lifecycle: open → allocated → fulfilled → released | expired | cancelled.
 *
 * The reserved_quantity counter on stock_levels is still maintained for
 * fast availability checks but is now always derived from active reservation rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();

            // What is being reserved
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('restrict');

            // How much
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_fulfilled')->default(0);

            // Lifecycle status
            $table->enum('status', [
                'open',        // Created, holding stock
                'allocated',   // Confirmed for fulfilment
                'fulfilled',   // Fully issued / deducted from on-hand
                'released',    // Released back to available pool (order changed/cancelled)
                'expired',     // Expired by TTL without being fulfilled
                'cancelled',   // Manually voided
            ])->default('open')->index();

            // Source document linkage (idempotency: one row per source line)
            $table->string('source_document_type', 60)->nullable(); // 'SalesOrder', 'WebOrder', 'PosOrder'…
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->unsignedBigInteger('source_document_line_id')->nullable();

            // TTL — auto-expire reservations that are never fulfilled
            $table->timestamp('expires_at')->nullable();

            // Actor
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();

            // Audit
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for common query patterns
            $table->index(['product_id', 'warehouse_id', 'status']);
            $table->index(['source_document_type', 'source_document_id']);
            $table->index('expires_at');

            // Prevent duplicate reservations for the same order line
            $table->unique([
                'source_document_type',
                'source_document_id',
                'source_document_line_id',
            ], 'uq_stock_reservation_source_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
