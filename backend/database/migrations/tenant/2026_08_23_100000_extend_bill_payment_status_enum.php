<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend `bill_payments.payment_status` to support the new payment lifecycle.
 *
 * Mirrors the migration that extended `payments.payment_status` (see
 * 2026_08_21_000001_extend_payment_status_enum.php): the original enum
 * (recorded/applied/unapplied/reversed/refunded) is too narrow for the
 * customer-invoice payment-import feature and correction flows — it must
 * accept 'draft', 'posted', and 'cancelled' (keeping the legacy values
 * for backward compatibility with the corrections/reversals code paths).
 *
 * PostgreSQL note: Laravel's enum() compiles to a CHECK constraint on a
 * string column. We drop the old constraint and add a new, wider one so
 * all valid lifecycle values are accepted.
 */
return new class extends Migration
{
    private const VALID_VALUES = [
        'draft',
        'posted',
        'recorded',
        'applied',
        'unapplied',
        'reversed',
        'refunded',
        'cancelled',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bill_payments') || ! Schema::hasColumn('bill_payments', 'payment_status')) {
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
        // No destructive rollback: removing statuses that may be in use would
        // break the accounting lifecycle. Leave the column widened.
    }

    private function extendPostgresCheckConstraint(): void
    {
        $constraintName = $this->findPostgresCheckConstraint('bill_payments', 'payment_status');
        if ($constraintName !== null) {
            DB::statement("ALTER TABLE bill_payments DROP CONSTRAINT \"{$constraintName}\"");
        }

        $allowed = implode("','", self::VALID_VALUES);
        DB::statement("ALTER TABLE bill_payments ADD CONSTRAINT bill_payments_payment_status_check CHECK (payment_status IN ('{$allowed}'))");
    }

    private function extendMysqlEnum(): void
    {
        $allowed = implode("','", self::VALID_VALUES);
        DB::statement("ALTER TABLE bill_payments MODIFY COLUMN payment_status ENUM('{$allowed}') NOT NULL DEFAULT 'recorded'");
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
