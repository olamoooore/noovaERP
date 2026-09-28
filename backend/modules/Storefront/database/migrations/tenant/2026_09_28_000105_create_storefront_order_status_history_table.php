<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_order_status_history — the audit trail required by FR-ORD-01:
 * every transition recorded by every writer (checkout, webhook, admin,
 * customer) through the ONE shared state machine. Terminal states have no
 * outgoing edges; invalid transitions are rejected before they reach here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('storefront_orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->enum('actor_type', ['account', 'staff', 'system', 'gateway']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_order_status_history');
    }
};
