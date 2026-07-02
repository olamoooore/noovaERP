<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the platform-wide activity_log table backing App\Features\ActivityLogging.
 *
 * Mirrors spatie/laravel-activitylog's schema and adds the v4 `event` and
 * `batch_uuid` columns that ActivityLogEntry writes. Previously this table was
 * only available as an unpublished vendor stub, so ActivityLogService had no
 * backing table (see OnboardingBackTest session evidence).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('activity_log')) {
            Schema::create('activity_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable();
                $table->text('description')->nullable();
                $table->nullableMorphs('subject', 'subject');
                $table->nullableMorphs('causer', 'causer');
                $table->string('event')->nullable();
                $table->json('properties')->nullable();
                $table->uuid('batch_uuid')->nullable();
                $table->timestamps();

                $table->index('log_name');
                $table->index('event');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
