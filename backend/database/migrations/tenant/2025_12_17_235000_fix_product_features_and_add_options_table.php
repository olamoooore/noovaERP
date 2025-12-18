<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure product_features has a slug column (controller/UI expect it)
        Schema::table('product_features', function (Blueprint $table) {
            if (!Schema::hasColumn('product_features', 'slug')) {
                $table->string('slug')->unique()->after('name');
            }
        });

        // Create a dedicated options table for select-type features.
        // (The existing product_feature_values table is used for per-product values.)
        if (!Schema::hasTable('product_feature_options')) {
            Schema::create('product_feature_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_feature_id')->constrained('product_features')->cascadeOnDelete();
                $table->string('value');
                $table->timestamps();

                $table->index(['product_feature_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feature_options');

        Schema::table('product_features', function (Blueprint $table) {
            if (Schema::hasColumn('product_features', 'slug')) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            }
        });
    }
};
