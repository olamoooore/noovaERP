<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entitlement logic — which catalog items require other catalog items.
     * Strictly separated from pricing and visibility concerns.
     */
    public function up(): void
    {
        Schema::create('catalog_dependencies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->foreignId('depends_on_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            // required = cannot activate item without depends_on
            // optional = suggested companion, not enforced
            $table->enum('type', ['required', 'optional'])->default('required');

            $table->timestamps();

            $table->unique(['item_id', 'depends_on_item_id']);
            $table->index('depends_on_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_dependencies');
    }
};
