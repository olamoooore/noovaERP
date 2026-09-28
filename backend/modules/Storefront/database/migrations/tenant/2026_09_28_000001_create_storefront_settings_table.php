<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant storefront settings singleton (Appendix E §E2).
     * Tenant-generic by construction: no tenant ids, brands, currencies or
     * domains are seeded here — defaults are platform-neutral.
     */
    public function up(): void
    {
        Schema::create('storefront_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('currency_code', 3)->nullable();
            $table->enum('tax_display', ['inclusive', 'exclusive'])->default('exclusive');
            $table->boolean('guest_checkout')->default(true);
            $table->boolean('allow_backorder')->default(false);
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->unsignedInteger('cart_ttl_days')->default(30);
            $table->string('checkout_terms_url')->nullable();
            $table->string('policy_returns_url')->nullable();
            $table->string('policy_shipping_url')->nullable();
            $table->string('policy_privacy_url')->nullable();
            $table->json('notification_settings')->nullable();
            $table->string('runtime_version', 8)->default('v1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_settings');
    }
};
