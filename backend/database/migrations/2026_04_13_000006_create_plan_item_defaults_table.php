<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Packaging Layer — Phase 6B.
     *
     * Defines how each catalog item behaves within each plan tier (plan_family).
     * This is the "Tier Matrix": rows = catalog items, columns = plan tiers.
     *
     * Availability modes:
     *   included     — bundled with the tier at no extra cost
     *   add_on       — purchasable separately on top of this tier
     *   hidden       — not shown or offered for this tier
     *   custom_only  — negotiated enterprise deals only, not self-serve
     *
     * When no row exists for a (plan_family, catalog_item) pair, the
     * effective availability is 'hidden' — safe default.
     */
    public function up(): void
    {
        Schema::create('plan_item_defaults', function (Blueprint $table) {
            $table->id();

            // Tier — plan_family is the commercial tier (Starter, Pro, Enterprise)
            $table->foreignId('plan_family_id')
                ->constrained('plan_families')
                ->cascadeOnDelete();

            // Catalog item being configured for this tier
            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            // How this item is offered within this tier
            $table->enum('availability', ['included', 'add_on', 'hidden', 'custom_only'])
                ->default('hidden');

            // Optional admin note explaining the decision
            $table->text('notes')->nullable();

            $table->timestamps();

            // One row per (tier × catalog item) — enforced at DB level
            $table->unique(['plan_family_id', 'catalog_item_id']);

            $table->index('plan_family_id');
            $table->index('catalog_item_id');
            $table->index('availability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_item_defaults');
    }
};
