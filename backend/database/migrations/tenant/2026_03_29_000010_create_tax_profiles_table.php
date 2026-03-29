<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_pack_id')->constrained('country_packs')->cascadeOnDelete();
            $table->string('tax_type');
            $table->decimal('rate', 8, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_default')->default(false);
            $table->jsonb('config')->nullable();
            $table->timestamps();

            $table->index(['country_pack_id', 'tax_type']);
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_profiles');
    }
};
