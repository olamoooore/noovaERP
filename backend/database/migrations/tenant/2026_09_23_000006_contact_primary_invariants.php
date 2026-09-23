<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #121 (hostile re-review 2026-09-23): the "one primary per contact"
 * invariant was enforced only in application code — no partial unique index,
 * no row lock, and existing multi-primary rows were never cleaned up. Two
 * concurrent addAddress/addMethod calls could both clear and both insert.
 *
 * This migration:
 *   1. Backfills each table so at most one row per owner keeps each primary
 *      flag (lowest id wins; deterministic, no human needed).
 *   2. Adds partial unique indexes so the database enforces the invariant
 *      regardless of which writer touches the table.
 *
 * Partial indexes exclude soft-deleted rows (contact_addresses /
 * contact_methods) so deleting the primary row frees the slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Backfill ────────────────────────────────────────────────
        // (customer_contacts is already covered by the existing
        // customer_contacts_one_primary[_per_customer] partial indexes.)
        $this->keepLowestFlag('contact_addresses', 'contact_id', 'is_primary', softDeletes: true);
        $this->keepLowestFlag('contact_addresses', 'contact_id', 'is_default_billing', softDeletes: true);
        $this->keepLowestFlag('contact_addresses', 'contact_id', 'is_default_shipping', softDeletes: true);
        $this->keepLowestFlag('contact_methods', 'contact_id', 'is_primary', softDeletes: true);

        // ── 2. Partial unique indexes ─────────────────────────────────
        if (Schema::hasTable('contact_addresses')) {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS contact_addresses_primary_unique
                ON contact_addresses (contact_id)
                WHERE is_primary AND deleted_at IS NULL
            ");
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS contact_addresses_default_billing_unique
                ON contact_addresses (contact_id)
                WHERE is_default_billing AND deleted_at IS NULL
            ");
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS contact_addresses_default_shipping_unique
                ON contact_addresses (contact_id)
                WHERE is_default_shipping AND deleted_at IS NULL
            ");
        }

        if (Schema::hasTable('contact_methods')) {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS contact_methods_primary_unique
                ON contact_methods (contact_id)
                WHERE is_primary AND deleted_at IS NULL
            ");
        }

    }

    /**
     * Unset the flag on every row except the lowest-id one per owner.
     */
    private function keepLowestFlag(string $table, string $ownerColumn, string $flagColumn, bool $softDeletes): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $flagColumn)) {
            return;
        }

        $outerDeleted = $softDeletes ? 'AND a.deleted_at IS NULL' : '';
        $innerDeleted = $softDeletes ? 'AND b.deleted_at IS NULL' : '';

        DB::statement("
            UPDATE {$table} a
            SET {$flagColumn} = false
            WHERE a.{$flagColumn} = true
              {$outerDeleted}
              AND EXISTS (
                  SELECT 1 FROM {$table} b
                  WHERE b.{$ownerColumn} = a.{$ownerColumn}
                    AND b.{$flagColumn} = true
                    AND b.id < a.id
                    {$innerDeleted}
              )
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contact_addresses_primary_unique');
        DB::statement('DROP INDEX IF EXISTS contact_addresses_default_billing_unique');
        DB::statement('DROP INDEX IF EXISTS contact_addresses_default_shipping_unique');
        DB::statement('DROP INDEX IF EXISTS contact_methods_primary_unique');
    }
};
