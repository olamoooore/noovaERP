<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds procurement linkage columns to the bills table and creates the
 * goods_receipts + goods_receipt_lines tables.
 *
 * Fixes:
 *  - Bills had no purchase_order_id — zero structural PO→Bill linkage
 *  - No formal GRN document — receiving was a PO status update only
 *  - No three-way matching is possible without PO→GRN→Bill linkage
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Goods Receipt Header ─────────────────────────────────────────
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();

            $table->string('grn_number')->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('received_date');
            $table->string('carrier_reference')->nullable();
            $table->string('status')->default('draft'); // draft, confirmed, cancelled
            $table-> text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('purchase_order_id');
            $table->index('warehouse_id');
            $table->index('received_date');
            $table->index('status');
        });

        // ── 2. Goods Receipt Lines ───────────────────────────────────────────
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->decimal('quantity_ordered', 15, 4)->default(0);
            $table->decimal('quantity_received', 15, 4)->default(0);
            $table->decimal('quantity_accepted', 15, 4)->default(0);
            $table->decimal('quantity_rejected', 15, 4)->default(0);
            $table->decimal('quantity_putaway', 15, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('goods_receipt_id');
            $table->index('product_id');
        });

        // ── 3. Add procurement linkage to bills ──────────────────────────────
        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();

            $table->foreignId('goods_receipt_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('goods_receipts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['goods_receipt_id']);
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn('purchase_order_id', 'goods_receipt_id');
        });

        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
