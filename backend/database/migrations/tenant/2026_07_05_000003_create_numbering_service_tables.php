<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('document_type', 50)->index();
            $table->string('prefix', 20);
            $table->integer('padding')->default(6);
            $table->string('format', 100);
            $table->unsignedBigInteger('current_number')->default(0);
            $table->string('reset_frequency', 20)->default('never'); // never, yearly, monthly
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
    }
};
