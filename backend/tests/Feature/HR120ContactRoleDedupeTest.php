<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #120 (hostile re-review 2026-09-23) — the widened contact_role_unique
 * index must be creatable on a tenant that already holds duplicates of the
 * widened key, and the merge must preserve role data.
 *
 * Reproduces the reported failure: rows that were legal under the old index
 * (tenant, contact, role, entity_id — NULLs distinct) are duplicates under the
 * widened key. The original migration failed CREATE UNIQUE INDEX on them,
 * aborting tenants:migrate for every later tenant migration.
 *
 * Verifies:
 *   1. The migration merges duplicate groups into the lowest-id survivor
 *      instead of aborting.
 *   2. Data is preserved: is_primary OR'd, metadata merged, role_label filled.
 *   3. The widened index exists afterwards and enforces uniqueness.
 *   4. Re-running the migration on an already-clean schema is a no-op.
 */
class HR120ContactRoleDedupeTest extends TenantTestCase
{
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });
        $this->bindTenantContext($tenant->id);
        $this->tenantId = $tenant->id;

        $this->assertTenantSchemaReady(['contact_roles']);
    }

    private function dropWidenedIndex(): void
    {
        DB::statement('ALTER TABLE contact_roles DROP CONSTRAINT IF EXISTS contact_role_unique');
        DB::statement('DROP INDEX IF EXISTS contact_role_unique');
    }

    private function runScopeMigration(): void
    {
        $migration = require base_path(
            'modules/Contact/database/migrations/tenant/2026_09_22_000016_scope_contact_roles_by_relationship.php'
        );
        $migration->up();
    }

    private function widenedKeyGroups(): array
    {
        return DB::select("
            SELECT tenant_id, contact_id, role,
                   COALESCE(entity_id, '00000000-0000-0000-0000-000000000000'::uuid) AS entity_key,
                   COALESCE(relationship_id, '00000000-0000-0000-0000-000000000000'::uuid) AS relationship_key,
                   COUNT(*) AS c
            FROM contact_roles
            GROUP BY 1,2,3,4,5
            HAVING COUNT(*) > 1
        ");
    }

    public function test_migration_merges_duplicates_and_preserves_data(): void
    {
        $this->dropWidenedIndex();

        $contactId = (string) Str::uuid();

        // Deterministic UUIDs so "lowest id" (Postgres byte-wise uuid order)
        // is unambiguous: ...0001 < ...0002.
        $survivorId = '00000000-0000-0000-0000-000000000001';
        $duplicateId = '00000000-0000-0000-0000-000000000002';

        DB::table('contact_roles')->insert([
            [
                'id' => $survivorId,
                'tenant_id' => $this->tenantId,
                'contact_id' => $contactId,
                'role' => 'CUSTOMER',
                'role_label' => null,
                'entity_id' => null,
                'relationship_id' => null,
                'is_primary' => false,
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $duplicateId,
                'tenant_id' => $this->tenantId,
                'contact_id' => $contactId,
                'role' => 'CUSTOMER',
                'role_label' => 'Key Account',
                'entity_id' => null,
                'relationship_id' => null,
                'is_primary' => true,
                'metadata' => json_encode(['segment' => 'key-account']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertCount(1, $this->widenedKeyGroups(), 'Precondition: duplicates exist under the widened key.');

        // The migration must not abort — it merges first.
        $this->runScopeMigration();

        $this->assertCount(0, $this->widenedKeyGroups(), 'All duplicate groups must be merged.');

        $survivor = DB::table('contact_roles')->where('id', $survivorId)->first();
        $this->assertNotNull($survivor, 'Lowest-id row must survive as the merge target.');
        $this->assertSame('Key Account', $survivor->role_label, 'role_label must be preserved from the duplicate.');
        $this->assertTrue((bool) $survivor->is_primary, 'is_primary must be OR-ed across the group.');
        $this->assertSame(
            ['segment' => 'key-account'],
            json_decode($survivor->metadata, true),
            'metadata must be merged from the duplicate.'
        );

        $this->assertNull(
            DB::table('contact_roles')->where('id', $duplicateId)->first(),
            'Non-survivor rows must be deleted.'
        );
    }

    public function test_widened_index_is_enforced_after_migration(): void
    {
        $this->dropWidenedIndex();
        $this->runScopeMigration();

        $contactId = (string) Str::uuid();

        $row = fn () => [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $contactId,
            'role' => 'VENDOR',
            'entity_id' => null,
            'relationship_id' => null,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('contact_roles')->insert($row());

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('contact_roles')->insert($row());
    }

    public function test_migration_is_idempotent_on_clean_schema(): void
    {
        // TenantTestCase already ran the migration once; re-running must not
        // throw and must leave the index in place.
        $this->runScopeMigration();
        $this->runScopeMigration();

        $index = DB::selectOne(
            "SELECT 1 AS present FROM pg_indexes WHERE tablename = 'contact_roles' AND indexname = 'contact_role_unique'"
        );
        $this->assertNotNull($index, 'The widened unique index must still exist.');
        $this->assertCount(0, $this->widenedKeyGroups());
    }
}
