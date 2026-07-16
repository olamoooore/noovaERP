<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_order_id')->constrained('web_orders')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable();

            // Product snapshot
            $table->string('product_name');
            $table->string('product_sku')->nullable();
            $table->string('product_image', 500)->nullable();

            // Pricing
            $table->decimal('unit_price', 15, 2);
            $table->integer('quantity');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);

            // Accounting reference
            $table->foreignId('income_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->timestamps();

            $table->index('web_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_order_items');
    }
};
