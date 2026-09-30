<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * MRD R9.3: auditor-visible human-in-the-loop evidence — every AI tax
 * advisory interaction (including deterministic fallbacks) is recorded with
 * its engine inputs, output shape and mode. tenant_ref is text because this
 * platform's tenant keys are UUIDs (schema-per-tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_ai_advisories', function ($table) {
            $table->id();
            $table->string('tenant_ref', 64)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('jurisdiction', 2)->nullable();
            $table->string('direction', 10)->nullable();
            $table->json('request');
            $table->json('engine_basis')->nullable();
            $table->string('mode', 30);
            $table->json('advice')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_ai_advisories');
    }
};
