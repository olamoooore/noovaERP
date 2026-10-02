<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #486: payments.method must accept any code the tenant method master
 * allows (DuitNow, PayNow, FPX …). The column is an enum/CHECK-constrained
 * list mirroring the OLD hardcoded validation list — exactly what the master
 * replaces. Drop the constraint (pg) / widen to VARCHAR (mysql) so the
 * application-level master is the single source of truth.
 *
 * Non-destructive: existing values are untouched; the down() deliberately
 * does not re-narrow the column (same precedent as
 * 2026_08_23_120000_extend_payments_method_enum).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'method')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $constraint = $this->findPostgresCheckConstraint('payments', 'method');
            if ($constraint !== null) {
                DB::statement("ALTER TABLE payments DROP CONSTRAINT \"{$constraint}\"");
            }
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE payments MODIFY COLUMN method VARCHAR(50) NOT NULL DEFAULT 'cash'");
        }
        // SQLite / others: column is already a plain string.
    }

    public function down(): void
    {
        // Intentionally left widening in place — re-narrowing would break
        // rails already recorded by tenants.
    }

    private function findPostgresCheckConstraint(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = ?::regclass
               AND contype = 'c'
               AND pg_get_constraintdef(oid) LIKE ?",
            [$table, "%{$column}%"]
        );

        return $row->conname ?? null;
    }
};
