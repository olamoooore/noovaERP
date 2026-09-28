<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_orders + items (Appendix E §E2). Money-correct shape:
 *  - order_number unique per tenant;
 *  - idempotency_key unique-per-tenant WHERE NOT NULL (FR-CO-05: checkout
 *    retry creates exactly one order);
 *  - guest_token_hash unique partial (guest order lookup, FR-CO-10);
 *  - legal_entity_id follows the web_orders/pos_orders precedent (nullable
 *    indexed column, backfillable from the customer) — entity stamping
 *    happens in the order service, never trusted from client payloads;
 *  - addresses/shipping/tracking are json snapshots (documents freeze the
 *    state at placement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('account_id')->nullable()->constrained('storefront_accounts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();

            $table->string('email', 255);
            $table->string('phone', 40)->nullable();

            $table->string('status', 30)->default('pending');
            $table->string('payment_status', 30)->default('pending_payment');

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('shipping_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('currency', 3)->nullable();

            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            $table->json('shipping_method')->nullable();
            $table->json('tax_breakdown')->nullable();

            $table->string('channel', 40)->default('storefront');
            $table->string('idempotency_key', 120)->nullable();
            $table->string('guest_token_hash', 64)->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('tracking_numbers')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payment_status', 'status']);
            $table->index('account_id');
            $table->index('customer_id');
            $table->index('email');
        });

        Schema::create('storefront_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('storefront_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('name', 255);
            $table->string('sku', 120)->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('quantity', 12, 3);
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->unsignedBigInteger('income_account_id')->nullable();
            $table->decimal('fulfilled_quantity', 12, 3)->default(0);
            $table->decimal('returned_quantity', 12, 3)->default(0);
            $table->timestamps();

            $table->index(['order_id', 'product_id']);
        });

        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("CREATE UNIQUE INDEX storefront_orders_idempotency ON \"{$schema}\".storefront_orders (idempotency_key) WHERE idempotency_key IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX storefront_orders_guest_token ON \"{$schema}\".storefront_orders (guest_token_hash) WHERE guest_token_hash IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_order_items');
        Schema::dropIfExists('storefront_orders');
    }
};
