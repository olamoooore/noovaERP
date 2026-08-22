<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `invoices.settlement_status` CHECK constraint.
 *
 * The original constraint only allows:
 *   'unpaid', 'partially_paid', 'paid', 'refunded'
 *
 * Some legacy code paths (notably the payment controllers and the payment
 * import service) wrote the short synonym 'partial' which violates this
 * constraint at the DB level. To remain backward-compatible with both naming
 * conventions while still enforcing the lifecycle, we accept both values.
 * New code should prefer the canonical 'partially_paid'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'settlement_status')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_settlement_status_check');
            DB::statement(
                "ALTER TABLE invoices ADD CONSTRAINT invoices_settlement_status_check ".
                "CHECK (settlement_status IN ('unpaid', 'partially_paid', 'partial', 'paid', 'refunded'))"
            );
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE invoices MODIFY COLUMN settlement_status ".
                "ENUM('unpaid', 'partially_paid', 'partial', 'paid', 'refunded') NULL"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'settlement_status')) {
            return;
        }

        DB::statement("UPDATE invoices SET settlement_status = 'partially_paid' WHERE settlement_status = 'partial'");

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_settlement_status_check');
            DB::statement(
                "ALTER TABLE invoices ADD CONSTRAINT invoices_settlement_status_check ".
                "CHECK (settlement_status IN ('unpaid', 'partially_paid', 'paid', 'refunded'))"
            );
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE invoices MODIFY COLUMN settlement_status ".
                "ENUM('unpaid', 'partially_paid', 'paid', 'refunded') NULL"
            );
        }
    }
};
