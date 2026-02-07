<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            
            // Contact info
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            
            // Shipping address stored as JSON
            $table->jsonb('shipping_address')->nullable();
            
            // Status
            $table->string('status')->default('pending'); // pending, confirmed, processing, shipped, delivered, cancelled, refunded
            $table->string('payment_status')->default('pending'); // pending, paid, failed, refunded
            
            // Financials
            $table->decimal('subtotal', 15, 2);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('loyalty_discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->string('currency', 3)->default('USD');
            
            // References
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedBigInteger('payment_transaction_id')->nullable();
            
            // Loyalty
            $table->integer('loyalty_points_earned')->default(0);
            $table->integer('loyalty_points_redeemed')->default(0);
            
            // Metadata
            $table->text('user_agent')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('notes')->nullable();
            
            // Guest tracking
            $table->string('guest_token')->nullable()->index();
            
            // Timestamps
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('status');
            $table->index('payment_status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_orders');
    }
};
