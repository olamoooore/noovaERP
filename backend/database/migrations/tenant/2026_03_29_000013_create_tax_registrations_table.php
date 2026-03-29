<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->foreignId('tax_profile_id')->constrained('tax_profiles')->cascadeOnDelete();
            $table->string('registration_number');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['legal_entity_id', 'tax_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_registrations');
    }
};
