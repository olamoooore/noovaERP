<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_promotion_redemptions — one row per applied promotion order
 * (Appendix-E §E2: UNIQUE per order, so a code is single-use per order and
 * usage counters are derived, never a mutable balance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('storefront_promotions')->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained('storefront_orders')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('storefront_accounts')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_promotion_redemptions');
    }
};
