<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('wb_pages')->onDelete('cascade');
            $table->integer('version')->default(1);
            $table->json('content');
            $table->boolean('is_current')->default(true);
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['page_id', 'is_current']);
            $table->index('version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_page_versions');
    }
};
