<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_governance_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable();

            $table->uuid('ai_execution_id')->unique();
            $table->string('agent_id', 100)->index();
            $table->string('agent_version', 50)->nullable();
            $table->string('model_provider', 100)->nullable();
            $table->string('model_name', 100)->nullable();
            $table->string('model_version', 50)->nullable();
            $table->string('instruction_version', 50)->nullable();
            $table->string('policy_version', 50)->nullable();

            $table->json('source_records')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('input_snapshot_hash', 64)->nullable();
            $table->string('output_hash', 64)->nullable();

            $table->text('recommendation')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('jurisdiction_context', 50)->nullable();
            $table->date('effective_date_context')->nullable();
            $table->float('execution_time')->nullable();
            $table->string('actor_context', 100)->nullable();

            $table->string('human_decision', 50)->nullable();
            $table->timestamp('human_decision_at')->nullable();
            $table->string('status', 30)->default('PENDING_REVIEW');

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_governance_records');
    }
};
