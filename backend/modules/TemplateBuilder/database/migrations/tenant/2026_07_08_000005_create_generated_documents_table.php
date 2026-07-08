<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type', 40)->index();
            $table->string('document_id', 64)->index();   // source record id
            $table->uuid('template_id')->nullable();
            $table->unsignedInteger('template_version')->default(0);
            $table->string('render_hash', 64)->index();
            $table->string('output_format', 8)->default('pdf'); // pdf | html | png
            $table->string('disk_path')->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
