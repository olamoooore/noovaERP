<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant Selection Layer — Phase 6C.
     *
     * Records what a tenant has explicitly selected beyond their base tier defaults.
     * The base tier is resolved from the tenant's active subscription → plan_version → plan_family.
     *
     * selection_type:
     *   add_on_purchased  — tenant purchased this add-on item on top of their base tier
     *   override_include  — admin explicitly granted this item regardless of tier matrix
     *   override_exclude  — admin explicitly blocked this item even if tier matrix includes it
     *
     * Resolution order (EntitlementService):
     *   1. Start with all 'included' items from tier matrix (plan_item_defaults)
     *   2. Add add_on_purchased items
     *   3. Add override_include items
     *   4. Remove override_exclude items
     */
    public function up(): void
    {
        Schema::create('tenant_selected_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->enum('selection_type', [
                'add_on_purchased',
                'override_include',
                'override_exclude',
            ]);

            $table->text('notes')->nullable();

            // Admin who made this selection
            $table->foreignId('selected_by_admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // One selection record per tenant × catalog item
            $table->unique(['tenant_id', 'catalog_item_id']);

            $table->index('tenant_id');
            $table->index('catalog_item_id');
            $table->index('selection_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_selected_items');
    }
};
