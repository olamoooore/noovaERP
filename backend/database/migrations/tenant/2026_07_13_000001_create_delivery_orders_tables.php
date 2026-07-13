<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_order_number')->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('restrict');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->enum('status', [
                'draft',
                'confirmed',
                'cancelled',
            ])->default('draft')->index();

            $table->date('delivery_date')->nullable();
            $table->date('expected_date')->nullable();

            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('shipping_address')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('delivery_date');
            $table->index('created_at');
        });

        Schema::create('delivery_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained('delivery_orders')->onDelete('cascade');
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->onDelete('restrict');
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('restrict');

            $table->string('description')->nullable();
            $table->decimal('quantity_ordered', 15, 4)->default(0);
            $table->decimal('quantity_delivered', 15, 4)->default(0);
            $table->string('unit', 20)->default('pcs');

            $table->timestamps();

            $table->index('delivery_order_id');
            $table->index('sales_order_line_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_order_lines');
        Schema::dropIfExists('delivery_orders');
    }
};
