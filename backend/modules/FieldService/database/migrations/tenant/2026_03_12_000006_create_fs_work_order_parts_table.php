<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fs_work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 10, 3)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0.00);
            $table->string('lot_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->boolean('stock_deducted')->default(false);
            $table->timestamps();

            $table->index('work_order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_work_order_parts');
    }
};
