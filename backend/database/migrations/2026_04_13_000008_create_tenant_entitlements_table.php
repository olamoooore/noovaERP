<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resolved entitlement snapshot for each tenant.
     *
     * This table is fully rebuilt by EntitlementService::rebuild() whenever:
     *   - The tenant's subscription / plan changes
     *   - The tier matrix (plan_item_defaults) changes
     *   - A tenant selection is added or removed
     *   - An admin triggers a manual rebuild
     *
     * Never read live rules from plan_item_defaults or tenant_selected_items
     * in the ERP runtime — always read from this table (fast, flat, pre-resolved).
     *
     * source:
     *   tier_included     — came from plan_item_defaults (availability = included)
     *   add_on_purchased  — tenant explicitly purchased this add-on
     *   override_include  — admin granted it regardless of tier
     *   override_exclude  — admin blocked it (is_entitled = false)
     */
    public function up(): void
    {
        Schema::create('tenant_entitlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->boolean('is_entitled')->default(true);

            $table->enum('source', [
                'tier_included',
                'add_on_purchased',
                'override_include',
                'override_exclude',
            ]);

            // When this entitlement was last resolved
            $table->timestamp('resolved_at')->useCurrent();

            $table->timestamps();

            // One entitlement record per tenant × catalog item
            $table->unique(['tenant_id', 'catalog_item_id']);

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_entitled']);
            $table->index('catalog_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_entitlements');
    }
};
