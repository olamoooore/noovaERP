<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #62 (hostile re-review HR62-01): rows reaching the publisher attempt
 * limit were silently excluded from the query ("left for manual review") —
 * no explicit terminal state, no operational visibility, no governed requeue.
 *
 * Adds an explicit dead-letter disposition plus recovery provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_outbox_events')) {
            return;
        }

        Schema::table('accounting_outbox_events', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_outbox_events', 'dead_lettered_at')) {
                $table->timestamp('dead_lettered_at')->nullable()->index();
            }
            if (! Schema::hasColumn('accounting_outbox_events', 'requeued_at')) {
                $table->timestamp('requeued_at')->nullable();
            }
            if (! Schema::hasColumn('accounting_outbox_events', 'requeue_count')) {
                $table->unsignedInteger('requeue_count')->default(0);
            }
            if (! Schema::hasColumn('accounting_outbox_events', 'publish_failure_history')) {
                $table->json('publish_failure_history')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_outbox_events')) {
            return;
        }

        Schema::table('accounting_outbox_events', function (Blueprint $table) {
            foreach (['dead_lettered_at', 'requeued_at', 'requeue_count', 'publish_failure_history'] as $column) {
                if (Schema::hasColumn('accounting_outbox_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
