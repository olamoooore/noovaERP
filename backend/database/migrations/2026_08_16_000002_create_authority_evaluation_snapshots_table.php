<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_evaluation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('action_eligibility_evaluation_id')->unique();
            $table->string('requested_action', 100)->index();
            $table->string('record_type', 100)->index();
            $table->string('record_id', 100)->index();
            $table->integer('record_version')->nullable();

            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable();
            $table->uuid('actor_id');

            $table->string('context_snapshot_id', 100)->nullable();
            $table->string('context_snapshot_hash', 64)->nullable();

            $table->json('policy_versions')->nullable();
            $table->json('state_versions')->nullable();
            $table->json('gate_results');

            $table->string('final_result', 20);
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('evaluated_at');

            $table->string('correlation_id', 100)->nullable()->index();

            $table->timestamps();

            $table->index(['tenant_id', 'record_type', 'record_id']);
            $table->index(['tenant_id', 'requested_action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_evaluation_snapshots');
    }
};
