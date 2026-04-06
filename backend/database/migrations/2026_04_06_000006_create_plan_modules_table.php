<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->cascadeOnDelete();
            $table->foreignId('module_catalog_id')->constrained('module_catalog')->cascadeOnDelete();
            $table->boolean('is_included')->default(true);
            $table->timestamps();

            $table->unique(['plan_version_id', 'module_catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_modules');
    }
};
