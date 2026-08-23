<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-deleted payments must not block re-importing a payment_number.
 *
 * The original schema used a plain UNIQUE index on payments.payment_number,
 * which means a soft-deleted row still occupies the number forever. Clients
 * re-importing their own payment numbers after deleting draft rows hit
 * duplicate-key errors through no fault of their own.
 *
 * PostgreSQL supports partial unique indexes, so we scope uniqueness to
 * live rows only (deleted_at IS NULL). On other drivers we fall back to a
 * composite index including deleted_at (best-effort equivalent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $driver = DB::getDriverName();

        // Drop the blanket unique index (default Laravel name).
        try {
            Schema::table('payments', function ($table) {
                $table->dropUnique('payments_payment_number_unique');
            });
        } catch (\Throwable $e) {
            // Index may already have been migrated away on this environment.
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS payments_payment_number_live_unique '
                .'ON payments (payment_number) WHERE deleted_at IS NULL'
            );
        } else {
            // MySQL/SQLite: emulate with a nullable deleted_at composite unique.
            Schema::table('payments', function ($table) {
                $table->unique(['payment_number', 'deleted_at'], 'payments_payment_number_live_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payments_payment_number_live_unique');
        } else {
            try {
                Schema::table('payments', function ($table) {
                    $table->dropUnique('payments_payment_number_live_unique');
                });
            } catch (\Throwable $e) {
                // ignore
            }
        }

        Schema::table('payments', function ($table) {
            $table->unique('payment_number', 'payments_payment_number_unique');
        });
    }
};
