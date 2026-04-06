<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_catalog_id')
                ->constrained('module_catalog')
                ->cascadeOnDelete();
            $table->foreignId('depends_on_module_catalog_id')
                ->constrained('module_catalog')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['module_catalog_id', 'depends_on_module_catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_dependencies');
    }
};
