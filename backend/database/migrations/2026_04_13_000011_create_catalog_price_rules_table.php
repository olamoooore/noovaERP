<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pricing modifiers applied during quote calculation.
     *
     * rule_type / condition / modifier_value examples:
     *
     *   percentage_discount
     *     condition:       {"tier_key": "enterprise"}   (null = all tiers)
     *     modifier_value:  20.00                        (20% off)
     *
     *   fixed_discount
     *     condition:       {"catalog_item_id": 7}       (specific item only)
     *     modifier_value:  500                          ($5.00 off, in cents)
     *
     *   yearly_discount
     *     condition:       {}                           (applies when billing_interval = yearly)
     *     modifier_value:  16.67                        (2 months free = ~16.67%)
     *
     *   volume_tier
     *     condition:       {"min_items": 5}             (5+ add-ons unlock discount)
     *     modifier_value:  10.00                        (10% off all add-ons)
     *
     * Rules are evaluated in priority order (highest first).
     * Multiple rules can stack unless is_exclusive = true.
     */
    public function up(): void
    {
        Schema::create('catalog_price_rules', function (Blueprint $table) {
            $table->id();

            // null = applies globally (all items)
            $table->foreignId('catalog_item_id')
                ->nullable()
                ->constrained('catalog_items')
                ->nullOnDelete();

            $table->string('name');
            $table->string('rule_type');       // percentage_discount, fixed_discount, yearly_discount, volume_tier
            $table->json('condition');         // open-ended matching criteria
            $table->decimal('modifier_value', 10, 4); // percentage or cents

            // Higher priority = evaluated first
            $table->unsignedSmallInteger('priority')->default(0);

            // If true, no other rules apply to the same item after this one
            $table->boolean('is_exclusive')->default(false);

            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->useCurrent();
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index('catalog_item_id');
            $table->index('rule_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_price_rules');
    }
};
