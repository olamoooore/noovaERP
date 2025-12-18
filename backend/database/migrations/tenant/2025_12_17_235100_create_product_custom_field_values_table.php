<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_custom_field_values')) {
            Schema::create('product_custom_field_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_custom_field_id')->constrained('product_custom_fields')->cascadeOnDelete();
                $table->text('value')->nullable();
                $table->timestamps();

                $table->unique(['product_id', 'product_custom_field_id'], 'pcfv_product_field_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_custom_field_values');
    }
};
