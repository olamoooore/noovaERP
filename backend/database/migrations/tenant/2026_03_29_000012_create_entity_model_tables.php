<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('base_currency', 3)->default('USD');
            $table->string('fiscal_year_start', 5)->default('01-01');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('country_code', 2)->nullable();
            $table->string('base_currency', 3)->default('USD');
            $table->string('fiscal_year_start', 5)->default('01-01');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('group_id');
            $table->index('country_code');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code']);
        });

        Schema::create('entity_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->string('book_type')->comment('general, statutory, management, tax');
            $table->string('name');
            $table->string('currency_code', 3);
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['legal_entity_id', 'book_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_books');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('legal_entities');
        Schema::dropIfExists('groups');
    }
};
