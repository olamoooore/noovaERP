<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mutual exclusion logic — items that cannot coexist in the same tenant config.
     * Strictly separated from dependency and pricing concerns.
     */
    public function up(): void
    {
        Schema::create('catalog_incompatibilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->foreignId('incompatible_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            // Human-readable explanation surfaced in the admin UI
            $table->text('reason')->nullable();

            $table->timestamps();

            // Relationship is directional in DB but treated as symmetric in code
            $table->unique(['item_id', 'incompatible_item_id']);
            $table->index('incompatible_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_incompatibilities');
    }
};
