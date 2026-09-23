<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #120 (hostile re-review 2026-09-23): 2026_09_22_000016 widened
 * contact_role_unique to include relationship_id, but a tenant already holding
 * duplicates of the new key (the commit message itself admitted they could
 * exist) fails CREATE UNIQUE INDEX — blocking tenants:migrate.
 *
 * Dedupe first (keep the lowest id per key), then re-assert the index so the
 * migration is safe on dirty data and idempotent on clean data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_roles')) {
            return;
        }

        $sentinel = '00000000-0000-0000-0000-000000000000';

        DB::statement(<<<SQL
            DELETE FROM contact_roles a
            USING contact_roles b
            WHERE a.id > b.id
              AND a.tenant_id = b.tenant_id
              AND a.contact_id = b.contact_id
              AND a.role = b.role
              AND COALESCE(a.entity_id, '{$sentinel}'::uuid) = COALESCE(b.entity_id, '{$sentinel}'::uuid)
              AND COALESCE(a.relationship_id, '{$sentinel}'::uuid) = COALESCE(b.relationship_id, '{$sentinel}'::uuid)
        SQL);

        DB::statement('DROP INDEX IF EXISTS contact_role_unique');
        DB::statement("
            CREATE UNIQUE INDEX contact_role_unique
            ON contact_roles (
                tenant_id, contact_id, role,
                COALESCE(entity_id, '{$sentinel}'::uuid),
                COALESCE(relationship_id, '{$sentinel}'::uuid)
            )
        ");
    }

    public function down(): void
    {
        // No-op: the dedupe is destructive by design; the widened index stays.
    }
};
