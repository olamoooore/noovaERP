<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type', 40)->index();
            $table->string('code', 60)->nullable();
            $table->string('name');
            $table->string('description')->nullable();

            $table->string('mode', 12)->default('block');   // block | code | blade
            $table->string('format', 12)->default('A4');     // A4 | Letter | A5 | 80mm | 58mm | custom
            $table->string('orientation', 9)->default('portrait');
            $table->unsignedSmallInteger('paper_width_mm')->nullable();
            $table->unsignedSmallInteger('paper_height_mm')->nullable();
            $table->json('margin')->nullable();
            $table->string('default_font', 60)->nullable();
            $table->string('default_locale', 8)->nullable();

            $table->json('layout_config')->nullable();
            $table->longText('html')->nullable();
            $table->longText('css')->nullable();

            $table->string('status', 12)->default('draft'); // draft | published | archived
            $table->unsignedInteger('published_version')->default(0);
            $table->boolean('is_system_default')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
