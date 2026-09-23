<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD: invoice-standardized-flow-mrd.md §5.1
 *
 * Adds the approval / delivery / evidence dimensions to invoices. These are
 * orthogonal to the existing status, lifecycle_status, settlement_status and
 * reconciliation_status columns; each new column has a single writer:
 *   - approval_status   → InvoiceApprovalService
 *   - delivery_status   → InvoiceDeliveryService
 *   - evidence_status   → InvoiceEvidenceService
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'requires_approval')) {
                $table->boolean('requires_approval')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'approval_status')) {
                $table->string('approval_status', 20)->default('not_required');
            }
            if (! Schema::hasColumn('invoices', 'approval_request_id')) {
                $table->unsignedBigInteger('approval_request_id')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'delivery_status')) {
                $table->string('delivery_status', 20)->default('not_sent');
            }
            if (! Schema::hasColumn('invoices', 'evidence_status')) {
                $table->string('evidence_status', 20)->default('not_required');
            }
            if (! Schema::hasColumn('invoices', 'payment_link_url')) {
                $table->text('payment_link_url')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'payment_link_expires_at')) {
                $table->timestamp('payment_link_expires_at')->nullable();
            }
        });

        foreach ([
            'approval_status' => 'invoices_approval_status_index',
            'delivery_status' => 'invoices_delivery_status_index',
            'evidence_status' => 'invoices_evidence_status_index',
        ] as $column => $index) {
            if (Schema::hasColumn('invoices', $column) && ! $this->indexExists($index)) {
                Schema::table('invoices', fn (Blueprint $table) => $table->index($column));
            }
        }

        if (Schema::hasTable('approval_requests')
            && Schema::hasColumn('invoices', 'approval_request_id')
            && ! $this->constraintExists('invoices', 'invoices_approval_request_id_foreign')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('approval_request_id')
                    ->references('id')
                    ->on('approval_requests')
                    ->nullOnDelete();
            });
        }

        // Backfill (MRD NFR-7): invoices that were already issued under the old
        // flow are treated as delivered — the delivery dimension did not exist.
        DB::table('invoices')
            ->whereIn('status', ['posted', 'partial', 'paid', 'overdue'])
            ->where('delivery_status', 'not_sent')
            ->update(['delivery_status' => 'sent']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        if (Schema::hasColumn('invoices', 'approval_request_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropForeign(['approval_request_id']);
            });
        }

        foreach (['invoices_approval_status_index', 'invoices_delivery_status_index', 'invoices_evidence_status_index'] as $index) {
            if ($this->indexExists($index)) {
                DB::statement('DROP INDEX IF EXISTS "'.$this->currentSchema().'".'.$index);
            }
        }

        Schema::table('invoices', function (Blueprint $table) {
            foreach ([
                'requires_approval',
                'approval_status',
                'approval_request_id',
                'delivery_status',
                'evidence_status',
                'payment_link_url',
                'payment_link_expires_at',
            ] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function currentSchema(): string
    {
        return DB::selectOne('SELECT current_schema() AS s')->s;
    }

    private function indexExists(string $index): bool
    {
        return (bool) DB::scalar(
            'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ? LIMIT 1',
            [$index]
        );
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return (bool) DB::scalar(
            'SELECT 1 FROM pg_constraint c '
            .'JOIN pg_class t ON t.oid = c.conrelid '
            .'JOIN pg_namespace n ON n.oid = t.relnamespace '
            .'WHERE n.nspname = current_schema() AND t.relname = ? AND c.conname = ? LIMIT 1',
            [$table, $constraint]
        );
    }
};
