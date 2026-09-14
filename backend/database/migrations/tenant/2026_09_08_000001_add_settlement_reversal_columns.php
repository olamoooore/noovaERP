<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds reversal-tracking columns to the settlements and allocations tables
 * for compensating-record settlement reversals (P0-5, ACC-005B, issue #41).
 *
 * The original 2026_08_25_000001 / 000002 migrations were placed in the
 * central database/migrations/ path and never executed against tenant schemas,
 * leaving tenant tables without the required columns. This tenant-scope
 * migration runs after the original tables are created and applies the
 * columns idempotently to EVERY tenant schema.
 *
 * Idempotent: each column is added only if missing on the target schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Apply to every tenant schema (those matching the stancl/tenancy UUID
        // pattern) AND to the public schema in single-schema test mode. This
        // covers both production multi-tenant setups and the TenantTestCase
        // single-schema test environment.
        $schemas = $this->targetSchemas();
        if (empty($schemas)) {
            return;
        }

        foreach ($schemas as $schema) {
            $this->applyColumnsToSchema($schema);
            $this->applyIndexToSchema($schema);
            $this->applyForeignKeyToSchema($schema);
        }
    }

    public function down(): void
    {
        $schemas = $this->targetSchemas();

        foreach ($schemas as $schema) {
            $this->dropColumnsFromSchema($schema);
        }
    }

    /**
     * @return string[]
     */
    private function targetSchemas(): array
    {
        // Discover tenant schemas by looking at the stancl/tenancy namespaces.
        // Pattern matches UUIDs like `00000000-0000-0000-0000-000000000001`.
        $rows = DB::select(
            "SELECT nspname FROM pg_namespace
             WHERE nspname ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\$'"
        );

        $schemas = array_map(fn ($r) => $r->nspname, $rows);

        // Also include the current schema in case we're running in a
        // single-schema test environment where tenant tables live in 'public'.
        $current = DB::selectOne('SELECT current_schema() AS s')->s;
        if ($current && ! in_array($current, $schemas, true) && $current !== 'pg_catalog' && $current !== 'information_schema') {
            // Include public only if the relevant tables actually exist there.
            if ($this->tableExistsInSchema('public', 'allocations')) {
                $schemas[] = 'public';
            }
        }

        return $schemas;
    }

    private function tableExistsInSchema(string $schema, string $table): bool
    {
        $row = DB::selectOne(
            "SELECT 1 AS exists FROM information_schema.tables
             WHERE table_schema = ? AND table_name = ? LIMIT 1",
            [$schema, $table]
        );

        return $row !== null && (int) ($row->exists ?? 0) === 1;
    }

    private function applyColumnsToSchema(string $schema): void
    {
        $columns = [
            ['allocations', 'reversed_at', 'timestamp(0) without time zone NULL'],
            ['allocations', 'reversal_of_allocation_id', 'bigint NULL'],
            ['allocations', 'reversal_reason', 'varchar(500) NULL'],
            ['settlements', 'reversed_at', 'timestamp(0) without time zone NULL'],
            ['settlements', 'reversal_reason', 'varchar(500) NULL'],
        ];

        // Use double-quoted schema identifier because tenant schema names
        // contain hyphens (UUID format). Quote via concatenation with proper
        // escape to avoid SQL injection.
        $quoted = '"' . str_replace('"', '""', $schema) . '"';

        foreach ($columns as [$table, $column, $type]) {
            if (! $this->tableExistsInSchema($schema, $table)) {
                continue;
            }

            $exists = DB::scalar(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1",
                [$schema, $table, $column]
            );
            if (! $exists) {
                DB::statement("ALTER TABLE {$quoted}.{$table} ADD COLUMN {$column} {$type}");
            }
        }
    }

    private function applyIndexToSchema(string $schema): void
    {
        if (! $this->tableExistsInSchema($schema, 'allocations')) {
            return;
        }

        $idxExists = DB::scalar(
            "SELECT 1 FROM pg_indexes
             WHERE schemaname = ? AND tablename = 'allocations'
               AND indexname = 'allocations_reversal_of_allocation_id_idx' LIMIT 1",
            [$schema]
        );
        if (! $idxExists) {
            // CREATE INDEX syntax: CREATE INDEX index_name ON schema.table (col)
            // The index name itself must NOT be schema-qualified (PG syntax).
            // Schema-qualify only the table reference (quoted because of hyphens).
            $quoted = '"' . str_replace('"', '""', $schema) . '"';
            DB::statement("CREATE INDEX allocations_reversal_of_allocation_id_idx ON {$quoted}.allocations (reversal_of_allocation_id)");
        }
    }

    private function applyForeignKeyToSchema(string $schema): void
    {
        if (! $this->tableExistsInSchema($schema, 'allocations')) {
            return;
        }

        $fkExists = DB::scalar(
            "SELECT 1 FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = 'allocations'
               AND constraint_type = 'FOREIGN KEY'
               AND constraint_name = 'allocations_reversal_of_allocation_id_foreign' LIMIT 1",
            [$schema]
        );
        if (! $fkExists) {
            // Quote schema identifier (hyphens in UUID require quoting).
            $quoted = '"' . str_replace('"', '""', $schema) . '"';
            DB::statement(
                "ALTER TABLE {$quoted}.allocations
                 ADD CONSTRAINT allocations_reversal_of_allocation_id_foreign
                 FOREIGN KEY (reversal_of_allocation_id) REFERENCES {$quoted}.allocations(id) ON DELETE SET NULL"
            );
        }
    }

    private function dropColumnsFromSchema(string $schema): void
    {
        $quoted = '"' . str_replace('"', '""', $schema) . '"';

        foreach ([
            ['allocations', 'allocations_reversal_of_allocation_id_foreign', 'allocations_reversal_of_allocation_id_idx',
                ['reversal_reason', 'reversal_of_allocation_id', 'reversed_at']],
            ['settlements', null, null, ['reversal_reason', 'reversed_at']],
        ] as [$table, $fk, $idx, $cols]) {
            if (! $this->tableExistsInSchema($schema, $table)) {
                continue;
            }
            if ($fk) {
                DB::statement("ALTER TABLE {$quoted}.{$table} DROP CONSTRAINT IF EXISTS {$fk}");
            }
            if ($idx) {
                DB::statement("DROP INDEX IF EXISTS {$quoted}.{$idx}");
            }
            foreach ($cols as $col) {
                DB::statement("ALTER TABLE {$quoted}.{$table} DROP COLUMN IF EXISTS {$col}");
            }
        }
    }
};
