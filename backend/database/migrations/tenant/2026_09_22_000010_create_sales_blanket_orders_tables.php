<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #152: blanket orders / call-off agreements — a long-term customer
 * commitment (quantity + locked price + validity) against which individual
 * release sales orders are drawn down.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_blanket_orders')) {
            Schema::create('sales_blanket_orders', function (Blueprint $table) {
                $table->id();
                $table->uuid('tenant_id')->nullable()->index();
                $table->string('blanket_number')->unique();
                $table->unsignedBigInteger('customer_id');
                $table->string('name');
                $table->string('status', 20)->default('active'); // active|expired|cancelled
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->string('currency', 3)->default('USD');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'status']);
            });
        }

        if (! Schema::hasTable('sales_blanket_order_lines')) {
            Schema::create('sales_blanket_order_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('blanket_order_id');
                $table->unsignedBigInteger('product_id');
                $table->string('description')->nullable();
                $table->decimal('committed_qty', 15, 4);
                $table->decimal('released_qty', 15, 4)->default(0);
                $table->decimal('unit_price', 15, 2);
                $table->string('unit')->nullable();
                $table->timestamps();

                $table->foreign('blanket_order_id')->references('id')->on('sales_blanket_orders')->cascadeOnDelete();
                $table->index(['blanket_order_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_blanket_order_lines');
        Schema::dropIfExists('sales_blanket_orders');
    }
};
