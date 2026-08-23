<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend `payments.method` enum to include correction-flow values.
 *
 * The refund / reverse / unapply flows stamp new payment rows with
 * `method` of 'refund' / 'reverse' / 'unapply' so the original document
 * can be re-derived through its lineage. The original
 * `payments_method_check` constraint (cash/card/bank_transfer/check/other)
 * blocks those rows.
 */
return new class extends Migration
{
    private const VALID_METHODS = [
        'cash',
        'card',
        'bank_transfer',
        'check',
        'other',
        'refund',
        'reverse',
        'unapply',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'method')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->extendPostgresCheckConstraint();
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $this->extendMysqlEnum();
        } else {
            // SQLite / others: nothing to do (column is plain string).
        }
    }

    public function down(): void
    {
        // No destructive rollback: removing methods that may be in use would
        // break the refund/reverse correction flows. Leave the column widened.
    }

    private function extendPostgresCheckConstraint(): void
    {
        $constraintName = $this->findPostgresCheckConstraint('payments', 'method');
        if ($constraintName !== null) {
            DB::statement("ALTER TABLE payments DROP CONSTRAINT \"{$constraintName}\"");
        }

        $allowed = implode("','", self::VALID_METHODS);
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('{$allowed}'))");
    }

    private function extendMysqlEnum(): void
    {
        $allowed = implode("','", self::VALID_METHODS);
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('{$allowed}') NOT NULL DEFAULT 'cash'");
    }

    private function findPostgresCheckConstraint(string $table, string $column): ?string
    {
        $rows = DB::select(
            'SELECT conname AS name FROM pg_constraint '.
            'WHERE conrelid = ?::regclass AND contype = \'c\' '.
            'AND pg_get_constraintdef(oid) LIKE ?',
            [$table, '%'.$column.'%']
        );

        foreach ($rows as $row) {
            return $row->name;
        }

        return null;
    }
};
