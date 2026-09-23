<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #68 (hostile re-review HR68-02): ACC-011 physically deleted the
 * period_close posting reference (journal_entry_links) to bypass ACC-002's
 * idempotency guard. Posting-reference retirement must be an ACC-002-owned,
 * traceability-preserving lifecycle — not a delete.
 *
 * Adds supersession columns and narrows the uniqueness constraint to ACTIVE
 * (non-superseded) links, so a retired post link is preserved as history
 * while a fresh posting for the same source can be registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entry_links')) {
            return;
        }

        Schema::table('journal_entry_links', function (Blueprint $table) {
            if (! Schema::hasColumn('journal_entry_links', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable()->index();
            }
            if (! Schema::hasColumn('journal_entry_links', 'superseded_by_journal_entry_id')) {
                $table->unsignedBigInteger('superseded_by_journal_entry_id')->nullable();
            }
        });

        // Replace the strict unique with a partial unique over active links.
        // The old constraint (created via $table->unique) may exist as a
        // constraint or index depending on history — drop both defensively.
        DB::statement('ALTER TABLE journal_entry_links DROP CONSTRAINT IF EXISTS journal_entry_links_source_post_unique');
        DB::statement('DROP INDEX IF EXISTS journal_entry_links_source_post_unique');

        DB::statement('
            CREATE UNIQUE INDEX journal_entry_links_source_post_unique
            ON journal_entry_links (source_document_type, source_document_id, relation_type)
            WHERE superseded_at IS NULL
        ');
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entry_links')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS journal_entry_links_source_post_unique');
        DB::statement('
            CREATE UNIQUE INDEX journal_entry_links_source_post_unique
            ON journal_entry_links (source_document_type, source_document_id, relation_type)
        ');

        Schema::table('journal_entry_links', function (Blueprint $table) {
            if (Schema::hasColumn('journal_entry_links', 'superseded_at')) {
                $table->dropColumn('superseded_at');
            }
            if (Schema::hasColumn('journal_entry_links', 'superseded_by_journal_entry_id')) {
                $table->dropColumn('superseded_by_journal_entry_id');
            }
        });
    }
};
