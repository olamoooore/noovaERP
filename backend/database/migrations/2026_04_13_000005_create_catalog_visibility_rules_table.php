<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visibility and eligibility rules — controls which catalog items surface
     * per tier, region, or any arbitrary condition.
     *
     * rule_type examples:
     *   tier_whitelist   → condition: {"tiers": ["enterprise", "professional"]}
     *   tier_blacklist   → condition: {"tiers": ["trial"]}
     *   region_blacklist → condition: {"regions": ["US", "CA"]}
     *   min_plan_key     → condition: {"plan_key": "professional"}
     *   beta_only        → condition: {"flag": "beta_catalog_2026"}
     *
     * These are intentionally open-ended to avoid another hardcoded enum.
     */
    public function up(): void
    {
        Schema::create('catalog_visibility_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->string('rule_type');
            $table->json('condition');

            $table->timestamps();

            $table->index(['item_id', 'rule_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_visibility_rules');
    }
};
