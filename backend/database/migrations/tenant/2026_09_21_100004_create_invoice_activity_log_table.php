<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MRD: invoice-standardized-flow-mrd.md §5.4 — append-only activity timeline.
 *
 * No updated_at by design: rows are never updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_activity_log')) {
            return;
        }

        Schema::create('invoice_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('event', 50);
            $table->string('actor_type', 20)->default('system'); // user | system | customer
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['invoice_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_activity_log');
    }
};
