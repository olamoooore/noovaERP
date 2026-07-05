<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Workflow schemas — status transition definitions per entity type
        Schema::create('workflow_schemas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('entity_type', 50)->index();
            $table->string('initial_status', 50);
            $table->json('transitions');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type']);
        });

        // Workflow logs — audit trail of all status transitions
        Schema::create('workflow_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('entity_type', 50)->index();
            $table->string('entity_id', 50)->index();
            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->string('action', 50);
            $table->string('result', 20); // applied, rejected
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_logs');
        Schema::dropIfExists('workflow_schemas');
    }
};
