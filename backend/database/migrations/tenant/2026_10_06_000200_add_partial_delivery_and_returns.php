<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #461 (P1, DO9): partial delivery completion with backorder / short
 * close, and partial customer returns.
 *
 * A delivery order's `quantity_delivered` was fixed at creation and completion
 * shipped all of it, so a short shipment (7 of 10) could only be corrected by
 * reversing the whole order — and `reverseDeliveryOrder` is all-or-nothing, so
 * a partial customer return was impossible.
 *
 * This migration adds the storage for both:
 *   • `delivery_orders.backorder_of_id` links a back-order DO to the short
 *     delivery it was split from; `short_close_reason` records why the
 *     remainder was cancelled under the tenant's `cancel` policy.
 *   • `delivery_order_lines.quantity_backordered` / `quantity_cancelled` /
 *     `quantity_returned` track each line's remainder and what customers sent
 *     back.
 *   • `delivery_order_returns` + `delivery_order_return_lines` are the return
 *     document recording per-line quantity, reason, lot/serial. Restock runs
 *     through the #460 movement path (InventoryService `return` movements),
 *     never by flipping the whole delivery order to reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_orders')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('delivery_orders', 'backorder_of_id')) {
                    $table->foreignId('backorder_of_id')
                        ->nullable()
                        ->after('sales_order_id')
                        ->constrained('delivery_orders')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('delivery_orders', 'short_close_reason')) {
                    $table->text('short_close_reason')->nullable()->after('reversal_reason');
                }
            });
        }

        if (Schema::hasTable('delivery_order_lines')) {
            Schema::table('delivery_order_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('delivery_order_lines', 'quantity_backordered')) {
                    $table->decimal('quantity_backordered', 15, 4)->default(0)->after('quantity_delivered');
                }
                if (! Schema::hasColumn('delivery_order_lines', 'quantity_cancelled')) {
                    $table->decimal('quantity_cancelled', 15, 4)->default(0)->after('quantity_backordered');
                }
                if (! Schema::hasColumn('delivery_order_lines', 'quantity_returned')) {
                    $table->decimal('quantity_returned', 15, 4)->default(0)->after('quantity_cancelled');
                }
            });
        }

        if (Schema::hasTable('delivery_orders') && ! Schema::hasTable('delivery_order_returns')) {
            Schema::create('delivery_order_returns', function (Blueprint $table) {
                $table->id();
                $table->string('return_number')->unique();
                $table->foreignId('delivery_order_id')->constrained('delivery_orders')->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('status', 20)->default('completed')->index();
                $table->text('reason')->nullable();
                $table->text('notes')->nullable();
                // Draft credit note generated for this return (never posted here).
                $table->foreignId('credit_note_id')->nullable()->constrained('customer_credit_notes')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('returned_at')->nullable();
                $table->timestamps();

                $table->index('delivery_order_id');
            });
        }

        if (Schema::hasTable('delivery_order_returns') && ! Schema::hasTable('delivery_order_return_lines')) {
            Schema::create('delivery_order_return_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('delivery_order_return_id')->constrained('delivery_order_returns')->cascadeOnDelete();
                $table->foreignId('delivery_order_line_id')->constrained('delivery_order_lines')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->decimal('quantity', 15, 4);
                $table->string('reason', 500)->nullable();
                $table->string('lot_number', 100)->nullable();
                $table->string('batch_number', 100)->nullable();
                $table->date('expiry_date')->nullable();
                $table->unsignedBigInteger('product_batch_id')->nullable();
                $table->unsignedBigInteger('product_serial_id')->nullable();
                $table->json('serial_numbers')->nullable();
                $table->timestamps();

                $table->index('delivery_order_return_id');
                $table->index('delivery_order_line_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_order_return_lines');
        Schema::dropIfExists('delivery_order_returns');

        if (Schema::hasTable('delivery_order_lines')) {
            Schema::table('delivery_order_lines', function (Blueprint $table) {
                foreach (['quantity_backordered', 'quantity_cancelled', 'quantity_returned'] as $column) {
                    if (Schema::hasColumn('delivery_order_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('delivery_orders')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                if (Schema::hasColumn('delivery_orders', 'backorder_of_id')) {
                    $table->dropConstrainedForeignId('backorder_of_id');
                }
                if (Schema::hasColumn('delivery_orders', 'short_close_reason')) {
                    $table->dropColumn('short_close_reason');
                }
            });
        }
    }
};
