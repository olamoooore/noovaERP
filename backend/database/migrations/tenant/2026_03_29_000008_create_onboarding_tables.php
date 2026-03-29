<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->enum('mode', ['guided', 'expert'])->default('guided');
            $table->string('current_step', 50)->default('group_setup');
            $table->enum('status', ['in_progress', 'completed', 'abandoned'])->default('in_progress');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('onboarding_step_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('onboarding_sessions')->onDelete('cascade');
            $table->string('step_code', 50);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped'])->default('pending');
            $table->json('data')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'step_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_step_states');
        Schema::dropIfExists('onboarding_sessions');
    }
};
