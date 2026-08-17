<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('state_transitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();

            $table->string('aggregate_type', 100)->index();
            $table->string('aggregate_id', 100)->index();

            $table->string('state_dimension', 50)->index();
            $table->string('from_state', 50);
            $table->string('to_state', 50);

            $table->string('reason_code', 100)->nullable();
            $table->uuid('command_id')->nullable()->index();
            $table->uuid('workflow_instance_id')->nullable();

            $table->string('actor_id', 100);
            $table->string('actor_type', 50)->default('HUMAN');

            $table->string('correlation_id', 100)->nullable()->index();
            $table->string('causation_id', 100)->nullable();

            $table->string('metadata_hash', 64)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id', 'state_dimension']);
            $table->index(['tenant_id', 'aggregate_type', 'state_dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('state_transitions');
    }
};
