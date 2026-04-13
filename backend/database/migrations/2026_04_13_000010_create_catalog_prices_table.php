<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pricing Engine — Phase 6D.
     *
     * Base prices for catalog items. Prices are stored in cents to avoid
     * floating-point rounding errors in billing calculations.
     *
     * An item with no price row is treated as "contact sales" (custom_only).
     * An included item's price is displayed as $0.00 in quotes (included in tier).
     */
    public function up(): void
    {
        Schema::create('catalog_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->enum('billing_interval', ['monthly', 'yearly']);

            // ISO 4217 three-letter currency code
            $table->char('currency', 3)->default('USD');

            // Base price in minor units (cents) — e.g. 5000 = $50.00
            $table->unsignedInteger('unit_amount')->default(0);

            $table->timestamps();

            // One price per item × interval × currency
            $table->unique(['catalog_item_id', 'billing_interval', 'currency']);

            $table->index('catalog_item_id');
            $table->index(['billing_interval', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_prices');
    }
};
