<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();

            $table->string('sales_order_number')->unique();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');

            // Channel provenance
            $table->enum('channel', ['admin', 'pos', 'ecommerce'])->default('admin')->index();

            // Operational lifecycle state
            $table->enum('status', [
                'draft',
                'confirmed',
                'allocated',
                'fulfilled',
                'invoiced',
                'cancelled',
            ])->default('draft')->index();

            // Accounting state (separate from operational)
            $table->enum('accounting_status', [
                'not_posted',
                'partially_posted',
                'fully_posted',
                'reversed',
            ])->default('not_posted')->index();

            // Source document (which channel created this order)
            $table->string('source_document_type', 60)->nullable()->index();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->index(['source_document_type', 'source_document_id']);

            // Financial snapshot
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            // Reference data
            $table->string('customer_reference')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            // Pricing context (frozen at confirmation)
            $table->string('currency_code', 3)->default('USD');

            // Dates
            $table->date('order_date')->nullable();
            $table->date('required_date')->nullable();

            // Cancellation tracking
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            // Actor tracking
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('order_date');
            $table->index('created_at');
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('restrict');
            $table->unsignedBigInteger('variant_id')->nullable();

            // Line identification
            $table->string('description');
            $table->string('sku')->nullable();

            // Quantities
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('quantity_allocated', 15, 4)->default(0);
            $table->decimal('quantity_fulfilled', 15, 4)->default(0);
            $table->decimal('quantity_invoiced', 15, 4)->default(0);
            $table->decimal('quantity_cancelled', 15, 4)->default(0);

            // Unit of measure
            $table->string('unit', 20)->default('pcs');

            // Pricing (frozen at confirmation, immutable afterwards)
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('list_price', 15, 2)->nullable();
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);

            // Accounting references
            $table->unsignedBigInteger('income_account_id')->nullable();
            $table->foreign('income_account_id')->references('id')->on('chart_of_accounts')->onDelete('set null');

            // Line-level source document (for channel item references)
            $table->string('source_line_type', 60)->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();

            $table->timestamps();

            $table->index('product_id');
            $table->index(['source_line_type', 'source_line_id']);
        });

        Schema::create('sales_order_status_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('cascade');

            $table->enum('from_status', [
                'draft', 'confirmed', 'allocated', 'fulfilled', 'invoiced', 'cancelled',
            ])->nullable();
            $table->enum('to_status', [
                'draft', 'confirmed', 'allocated', 'fulfilled', 'invoiced', 'cancelled',
            ]);

            // Actor
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 60)->nullable();
            $table->string('source', 30)->default('admin');

            // Reasoning
            $table->text('reason')->nullable();

            // Idempotency / replay protection
            $table->string('idempotency_key', 120)->nullable()->index();

            $table->timestamp('transitioned_at')->nullable();
            $table->timestamps();

            $table->index('sales_order_id');
            $table->index('transitioned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_status_history');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};