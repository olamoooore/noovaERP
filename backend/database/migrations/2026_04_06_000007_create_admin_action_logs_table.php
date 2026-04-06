<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_action_logs', function (Blueprint $table) {
            $table->id();
            // Nullable so logs survive even if the admin user is deleted
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');        // e.g. "plan.created", "module.synced"
            $table->string('target_type')->nullable(); // e.g. "PlanFamily"
            $table->string('target_id')->nullable();   // e.g. "42"
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address')->nullable();
            $table->enum('status', ['success', 'failure'])->default('success');
            // Logs are immutable — only created_at, no updated_at
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_action_logs');
    }
};
