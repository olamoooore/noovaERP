<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant: Inventory tracking (stock movements)
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['in', 'out', 'adjustment'])->default('out');
            $table->integer('quantity');
            $table->string('reference')->nullable(); // Invoice #, PO #
            $table->string('reference_type')->nullable(); // Invoice, PurchaseOrder
            $table->foreignId('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->integer('balance_after')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('product_id');
            $table->index('type');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
