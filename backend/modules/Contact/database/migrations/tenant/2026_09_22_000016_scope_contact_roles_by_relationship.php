<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #120: contact_role_unique was (tenant_id, contact_id, role, entity_id);
 * relationship-scoped persistent roles carry entity_id = NULL, so the same role
 * under two different relationships was not distinguishable by the constraint
 * (and collides wherever entity_id is set). Widen it to include
 * relationship_id so the realistic case — one person holding the same role for
 * two related organisations — is representable, while exact duplicates remain
 * rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_roles')) {
            return;
        }

        DB::statement('ALTER TABLE contact_roles DROP CONSTRAINT IF EXISTS contact_role_unique');
        DB::statement('DROP INDEX IF EXISTS contact_role_unique');

        // COALESCE keeps NULL entity_id/relationship_id comparable (Postgres
        // treats NULLs as distinct in plain unique indexes, which is how exact
        // duplicates slipped through before).
        DB::statement("
            CREATE UNIQUE INDEX contact_role_unique
            ON contact_roles (
                tenant_id, contact_id, role,
                COALESCE(entity_id, '00000000-0000-0000-0000-000000000000'::uuid),
                COALESCE(relationship_id, '00000000-0000-0000-0000-000000000000'::uuid)
            )
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contact_role_unique');
        DB::statement('
            CREATE UNIQUE INDEX contact_role_unique
            ON contact_roles (tenant_id, contact_id, role, entity_id)
        ');
    }
};
