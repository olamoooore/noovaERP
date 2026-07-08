<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('template_id')->nullable();      // null = tenant-shared asset
            $table->string('kind', 20)->default('logo');  // logo|letterhead|background|font|signature
            $table->string('name')->nullable();
            $table->string('url');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('template_id')->references('id')->on('document_templates')->cascadeOnDelete();
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_assets');
    }
};
