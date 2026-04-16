<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_family_id')->constrained('plan_families')->cascadeOnDelete();
            $table->string('version_label')->default('v1');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_publicly_listed')->default(true);
            $table->timestamps();

            $table->unique(['plan_family_id', 'version_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_versions');
    }
};
