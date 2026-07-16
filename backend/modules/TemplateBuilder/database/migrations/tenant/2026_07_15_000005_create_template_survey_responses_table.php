<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-004 §13 — Survey response historical-integrity record.
 *
 * Stores immutable responses for SURVEY_ASSESSMENT templates: answers,
 * identity policy, calculated result, and the scoring version used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('template_id')->index();
            $table->unsignedInteger('template_version')->default(0);
            // Version of the scoring/branching engine that produced the result.
            $table->string('scoring_version', 40)->default('1');

            $table->json('answers')->nullable();
            // anonymous | identified | known_customer
            $table->string('identity_policy', 24)->default('anonymous');

            $table->string('respondent_token_id', 64)->nullable()->index();
            $table->string('respondent_email', 191)->nullable()->index();
            $table->foreignId('respondent_user_id')->nullable();

            // Calculated scoring outcome (score, bands, thresholds crossed).
            $table->json('calculated_result')->nullable();

            // open | closed | excluded
            $table->string('status', 16)->default('submitted')->index();

            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['template_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_survey_responses');
    }
};
