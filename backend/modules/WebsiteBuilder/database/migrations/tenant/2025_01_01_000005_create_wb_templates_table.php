<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('category', 100)->nullable(); // saas, business, portfolio, ecommerce
            $table->string('thumbnail', 500)->nullable();
            $table->json('schema'); // Full template structure
            $table->integer('version')->default(1);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->index('category');
            $table->index('is_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_templates');
    }
};
