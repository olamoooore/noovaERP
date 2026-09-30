<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_promotions — server-validated discount codes (FR-CO-09,
 * Appendix-E §E2 shape: code unique per tenant, type percent/fixed/
 * free_shipping, min_subtotal, usage_limit, per_account_limit, window,
 * is_active). The browser never carries a discount amount — only the code;
 * every authoritative number is recomputed at checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80);
            $table->string('type', 20); // percent | fixed | free_shipping
            $table->decimal('value', 15, 2)->default(0);
            $table->decimal('min_subtotal', 15, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_account_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_promotions');
    }
};
