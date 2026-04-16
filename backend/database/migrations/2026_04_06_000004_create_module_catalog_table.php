<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('module_key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['core', 'addon'])->default('core');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_catalog');
    }
};
