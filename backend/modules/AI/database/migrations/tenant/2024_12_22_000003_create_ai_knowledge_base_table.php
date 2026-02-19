<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50); // faq, product, policy
            $table->text('question');
            $table->text('answer');
            $table->json('keywords')->nullable();
            $table->string('language', 10)->default('en');
            $table->boolean('is_active')->default(true);
            $table->integer('usage_count')->default(0);
            $table->timestamps();
            
            $table->index('category');
            $table->index('is_active');
            $table->index('language');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_base');
    }
};
