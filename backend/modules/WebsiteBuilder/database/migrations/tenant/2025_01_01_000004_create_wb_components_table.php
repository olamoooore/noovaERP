<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_components', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 50); // text, image, button, form, section, container
            $table->json('schema')->nullable(); // Default properties
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_components');
    }
};
