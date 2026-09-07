<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-001 Opening Balance — immutable audit trail.
 *
 * Issue #70: every lifecycle transition, approval decision, and
 * posting event is recorded with actor, timestamp, and metadata.
 * The audit trail is append-only — rows are never updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_audit_trail', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');
            $table->string('action', 50);       // created, validated, approved, posted, etc.
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->unsignedBigInteger('actor_id');
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->default(DB::raw('NOW()'));

            // Indexes
            $table->index(['batch_id', 'action']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_audit_trail');
    }
};
