<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_product_links')) {
            return;
        }

        Schema::create('event_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->enum('relation_type', ['merchandise', 'bundle']);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'product_id', 'relation_type'], 'event_product_relation_unique');
            $table->index(['event_id', 'relation_type'], 'event_product_event_relation_idx');
            $table->index(['product_id', 'relation_type'], 'event_product_product_relation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_product_links');
    }
};
