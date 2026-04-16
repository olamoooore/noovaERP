<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks entitlement rebuild operations — full audit trail of
     * when entitlements were recalculated, why, and what the outcome was.
     *
     * tenant_id = null means a global rebuild (all tenants).
     */
    public function up(): void
    {
        Schema::create('entitlement_rebuild_jobs', function (Blueprint $table) {
            $table->id();

            // null = global rebuild (all tenants)
            $table->uuid('tenant_id')->nullable();

            $table->enum('status', ['pending', 'running', 'completed', 'failed'])
                ->default('pending');

            // What triggered this rebuild
            $table->string('reason')->nullable();

            // Admin who triggered it (null = system-triggered)
            $table->foreignId('triggered_by_admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedInteger('items_processed')->default(0);
            $table->unsignedInteger('tenants_processed')->default(0);
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_rebuild_jobs');
    }
};
