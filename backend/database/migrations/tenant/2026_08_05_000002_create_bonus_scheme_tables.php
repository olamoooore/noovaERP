<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('scheme_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['draft', 'active', 'inactive', 'expired'])->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('start_date');
            $table->index('end_date');
        });

        Schema::create('bonus_scheme_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonus_scheme_id')->constrained()->onDelete('cascade');
            $table->foreignId('qualifying_product_id')->constrained('products')->onDelete('restrict');
            $table->enum('scheme_type', ['fixed_discount', 'percentage_discount', 'buy_x_get_y']);
            $table->decimal('discount_amount', 15, 2)->nullable();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->decimal('minimum_purchase_qty', 15, 4)->nullable();
            $table->foreignId('foc_product_id')->nullable()->constrained('products')->onDelete('set null');
            $table->decimal('foc_qty', 15, 4)->nullable();
            $table->integer('priority')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('bonus_scheme_id');
            $table->index('qualifying_product_id');
            $table->index('status');
        });

        Schema::create('customer_bonus_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('bonus_scheme_id')->constrained()->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'suspended', 'expired'])->default('active');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('bonus_scheme_id');
            $table->index('status');
            $table->unique(['customer_id', 'bonus_scheme_id'], 'customer_scheme_unique');
        });

        Schema::create('bonus_scheme_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_bonus_scheme_id')->constrained()->onDelete('cascade');
            $table->foreignId('sales_order_item_id')->constrained('sales_order_lines')->onDelete('restrict');
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_lines')->onDelete('set null');
            $table->foreignId('medical_case_item_id')->nullable()->constrained('medical_case_items')->onDelete('set null');
            $table->decimal('qualifying_qty', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('foc_qty', 15, 4)->default(0);
            $table->enum('status', ['pending', 'applied', 'reversed'])->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index('customer_bonus_scheme_id');
            $table->index('sales_order_item_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_scheme_usages');
        Schema::dropIfExists('customer_bonus_schemes');
        Schema::dropIfExists('bonus_scheme_products');
        Schema::dropIfExists('bonus_schemes');
    }
};
