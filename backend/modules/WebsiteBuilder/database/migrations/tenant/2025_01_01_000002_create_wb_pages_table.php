<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('wb_websites')->onDelete('cascade');
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug', 100);
            $table->boolean('is_home')->default(false);
            $table->string('seo_title', 255)->nullable();
            $table->text('seo_description')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['website_id', 'slug']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_pages');
    }
};
