<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds correlation_id to ai_reasoning_records so every AI reasoning trace can
 * be tied back to the originating request (ADC §9 — traceability).
 *
 * The platform CorrelationIdMiddleware stamps every API request with an
 * X-Correlation-ID; AIReasoningRecordService now persists it here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_reasoning_records', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_reasoning_records', 'correlation_id')) {
                $table->string('correlation_id', 80)->nullable()->after('session_id');
                $table->index(['tenant_id', 'correlation_id']);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_reasoning_records', 'correlation_id')) {
            Schema::table('ai_reasoning_records', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'correlation_id']);
                $table->dropColumn('correlation_id');
            });
        }
    }
};
