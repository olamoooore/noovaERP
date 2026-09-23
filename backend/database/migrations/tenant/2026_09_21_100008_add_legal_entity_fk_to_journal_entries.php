<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #165: `journal_entries.legal_entity_id` was a bare unsignedBigInteger
 * with an index — no FK — so deleting a legal entity silently orphaned every
 * posted journal entry that referenced it. Every other entity-scoped table uses
 * `constrained(...)->cascadeOnDelete()`.
 *
 * This adds the DB-level backstop (ON DELETE RESTRICT) so the ledger can never
 * be orphaned even if an application guard is bypassed. Dangling references
 * from before the guard existed are repaired to NULL (the GL row survives and
 * simply loses its entity stamp — the referenced entity is already gone).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')
            || ! Schema::hasTable('legal_entities')
            || ! Schema::hasColumn('journal_entries', 'legal_entity_id')) {
            return;
        }

        if ($this->constraintExists('journal_entries', 'journal_entries_legal_entity_id_foreign')) {
            return;
        }

        $schema = '"'.str_replace('"', '""', (string) DB::selectOne('SELECT current_schema() AS s')->s).'"';

        // Repair pre-existing dangling references so the FK can be applied.
        DB::statement(
            "UPDATE {$schema}.journal_entries je
             SET legal_entity_id = NULL
             WHERE je.legal_entity_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$schema}.legal_entities le WHERE le.id = je.legal_entity_id
               )"
        );

        // DB backstop: an entity with GL history cannot be deleted.
        DB::statement(
            "ALTER TABLE {$schema}.journal_entries
             ADD CONSTRAINT journal_entries_legal_entity_id_foreign
             FOREIGN KEY (legal_entity_id) REFERENCES {$schema}.legal_entities(id)
             ON DELETE RESTRICT"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        $schema = '"'.str_replace('"', '""', (string) DB::selectOne('SELECT current_schema() AS s')->s).'"';

        DB::statement(
            "ALTER TABLE {$schema}.journal_entries
             DROP CONSTRAINT IF EXISTS journal_entries_legal_entity_id_foreign"
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
