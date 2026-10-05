<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #570 (MC7): partial deliveries need a `partially_delivered` case
 * status so a case that still has undelivered lines is not stamped
 * `delivered`. PostgreSQL note: Laravel's enum() compiles to a CHECK
 * constraint on a string column, so the old constraint is replaced with a
 * wider one.
 */
return new class extends Migration
{
    private const VALID_VALUES = [
        'draft',
        'allocated',
        'partially_delivered',
        'delivered',
        'invoiced',
        'completed',
        'cancelled',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('medical_cases') || ! Schema::hasColumn('medical_cases', 'status')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $allowed = implode("','", self::VALID_VALUES);

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE medical_cases DROP CONSTRAINT IF EXISTS medical_cases_status_check');
            DB::statement("ALTER TABLE medical_cases ADD CONSTRAINT medical_cases_status_check CHECK (status IN ('{$allowed}'))");
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE medical_cases MODIFY COLUMN status ENUM('{$allowed}') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        // No destructive rollback: rows may already carry 'partially_delivered'.
    }
};
