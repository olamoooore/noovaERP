<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_returns — FR-ORD-06/A-22 return lifecycle (Appendix-E §E2 shape:
 * order/account/status enum/reason/notes/refund_amount) plus the per-line
 * quantities captured at request time (line_items) that drive the receive
 * step's restock. Lifecycle: requested → approved|rejected → received →
 * refunded|closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('storefront_orders')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('storefront_accounts')->nullOnDelete();
            $table->string('status', 20)->default('requested');
            $table->string('reason', 500);
            $table->text('notes')->nullable();
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->json('line_items');
            $table->string('reviewed_by', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_returns');
    }
};
