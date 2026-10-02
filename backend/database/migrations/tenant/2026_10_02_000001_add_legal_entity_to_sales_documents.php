<?php

use App\Services\SalesLegalEntityBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #875 (P0, SA1): Sales documents had no legal entity of their own, so
 * every Sales journal entry (invoice, payment, credit note, standalone refund)
 * was posted with legal_entity_id = NULL — invisible to entity-filtered
 * reports and to entity-scoped users (JournalEntry::scopeAccessibleBy fails
 * closed on NULL).
 *
 * Adds legal_entity_id + entity_book_id to the Sales documents, and backfills
 * existing Sales documents/journal entries ONLY for single-entity tenants
 * (recorded decision 2026-10-02). Multi-entity tenants are never written
 * blindly; `php artisan accounting:sales-legal-entity-mapping-report` produces
 * the read-only mapping report the client approves first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = ['invoices', 'payments', 'customer_credit_notes', 'refund_transactions'];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'legal_entity_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('legal_entity_id')->nullable()->index();
                });
            }

            if (! Schema::hasColumn($table, 'entity_book_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('entity_book_id')->nullable()->index();
                });
            }
        }

        // Single-entity tenants: stamp existing Sales documents and journal
        // entries automatically. Multi-entity (or zero-entity) tenants: no-op.
        app(SalesLegalEntityBackfillService::class)->applySingleEntityBackfill();
    }

    public function down(): void
    {
        foreach (['invoices', 'payments', 'customer_credit_notes', 'refund_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (['entity_book_id', 'legal_entity_id'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($column) {
                        $blueprint->dropColumn($column);
                    });
                }
            }
        }
    }
};
