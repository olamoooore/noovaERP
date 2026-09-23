<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #120: contact_role_unique was (tenant_id, contact_id, role, entity_id);
 * relationship-scoped persistent roles carry entity_id = NULL, so the same role
 * under two different relationships was not distinguishable by the constraint
 * (and collides wherever entity_id is set). Widen it to include
 * relationship_id so the realistic case — one person holding the same role for
 * two related organisations — is representable, while exact duplicates remain
 * rejected.
 *
 * Hostile re-review 2026-09-23 (B1): the original version of this migration
 * dropped the old index and created the widened one without de-duplicating,
 * so any tenant holding rows that were legal under the old index (NULLs are
 * distinct in a plain unique index) failed CREATE UNIQUE INDEX — blocking
 * `tenants:migrate` for every later tenant migration.
 *
 * Fix: before creating the widened index, merge duplicate groups into the
 * lowest-id survivor, preserving data:
 *   - role_label: survivor's value, else the first non-null in the group
 *   - is_primary: logical OR across the group
 *   - metadata:   survivor's keys win, gaps filled from the group
 * The merged ids are logged so an operator has the audit trail; no group
 * needs human resolution because the merge is deterministic.
 */
return new class extends Migration
{
    private const SENTINEL = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        if (! Schema::hasTable('contact_roles')) {
            return;
        }

        DB::statement('ALTER TABLE contact_roles DROP CONSTRAINT IF EXISTS contact_role_unique');
        DB::statement('DROP INDEX IF EXISTS contact_role_unique');

        $this->deduplicate();

        // COALESCE keeps NULL entity_id/relationship_id comparable (Postgres
        // treats NULLs as distinct in plain unique indexes, which is how exact
        // duplicates slipped through before).
        DB::statement("
            CREATE UNIQUE INDEX contact_role_unique
            ON contact_roles (
                tenant_id, contact_id, role,
                COALESCE(entity_id, '".self::SENTINEL."'::uuid),
                COALESCE(relationship_id, '".self::SENTINEL."'::uuid)
            )
        ");
    }

    /**
     * Merge every duplicate group (under the widened key) into its
     * lowest-id survivor, then delete the rest.
     */
    private function deduplicate(): void
    {
        if (! Schema::hasColumn('contact_roles', 'relationship_id')) {
            return;
        }

        $sentinel = self::SENTINEL;

        $groups = DB::select("
            SELECT tenant_id, contact_id, role,
                   COALESCE(entity_id, '{$sentinel}'::uuid) AS entity_key,
                   COALESCE(relationship_id, '{$sentinel}'::uuid) AS relationship_key,
                   COUNT(*) AS row_count
            FROM contact_roles
            GROUP BY tenant_id, contact_id, role,
                     COALESCE(entity_id, '{$sentinel}'::uuid),
                     COALESCE(relationship_id, '{$sentinel}'::uuid)
            HAVING COUNT(*) > 1
        ");

        foreach ($groups as $group) {
            $rows = DB::select("
                SELECT id, role_label, is_primary, metadata
                FROM contact_roles
                WHERE tenant_id = ?
                  AND contact_id = ?
                  AND role = ?
                  AND COALESCE(entity_id, '{$sentinel}'::uuid) = ?
                  AND COALESCE(relationship_id, '{$sentinel}'::uuid) = ?
                ORDER BY id
            ", [
                $group->tenant_id,
                $group->contact_id,
                $group->role,
                $group->entity_key,
                $group->relationship_key,
            ]);

            $survivor = $rows[0];
            $roleLabel = $survivor->role_label;
            $isPrimary = (bool) $survivor->is_primary;
            $metadata = $survivor->metadata ? (json_decode($survivor->metadata, true) ?: []) : [];
            $mergedIds = [];

            foreach (array_slice($rows, 1) as $duplicate) {
                if ($roleLabel === null && $duplicate->role_label !== null) {
                    $roleLabel = $duplicate->role_label;
                }
                if ($duplicate->is_primary) {
                    $isPrimary = true;
                }
                if ($duplicate->metadata) {
                    $duplicateMetadata = json_decode($duplicate->metadata, true) ?: [];
                    // Survivor's keys win; the duplicate only fills gaps.
                    $metadata = array_merge($duplicateMetadata, $metadata);
                }
                $mergedIds[] = $duplicate->id;
            }

            DB::table('contact_roles')->where('id', $survivor->id)->update([
                'role_label' => $roleLabel,
                'is_primary' => $isPrimary,
                'metadata' => $metadata === [] ? null : json_encode($metadata),
                'updated_at' => now(),
            ]);

            DB::table('contact_roles')->whereIn('id', $mergedIds)->delete();

            Log::warning('contact_roles dedupe (issue #120): merged duplicates into survivor', [
                'survivor_id' => $survivor->id,
                'merged_ids' => $mergedIds,
                'tenant_id' => $group->tenant_id,
                'contact_id' => $group->contact_id,
                'role' => $group->role,
                'merged_is_primary' => $isPrimary,
            ]);
        }

        if ($groups !== []) {
            Log::warning('contact_roles dedupe (issue #120): completed', [
                'groups_merged' => count($groups),
            ]);
        }
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
