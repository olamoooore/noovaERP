<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();

            // Business key — stable, human-readable, globally unique
            // Convention: module[.submodule[.feature]]
            // e.g. "accounting", "accounting.payables", "accounting.payables.ocr"
            $table->string('key')->unique();

            $table->string('name');
            $table->text('description')->nullable();

            // Dynamic type system — no hardcoded enum
            $table->foreignId('type_id')
                ->constrained('catalog_item_types')
                ->restrictOnDelete();

            // Parent is for UI grouping ONLY — never use for business logic
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('catalog_items')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Arbitrary structured metadata (icon, colour, docs URL, etc.)
            $table->json('metadata')->nullable();

            // Temporal validity — enables versioning without a full versions table
            $table->date('effective_from')->useCurrent();
            $table->date('effective_to')->nullable();

            // Traceability — 'catalog' (new) or 'legacy_module' (migrated)
            $table->string('source')->default('catalog');

            $table->timestamps();

            $table->index('type_id');
            $table->index('parent_id');
            $table->index('is_active');
            $table->index(['effective_from', 'effective_to']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
