<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Hostile review repro (#120, raw PostgreSQL): the widened contact_role_unique
 * index must be creatable on a tenant that already holds duplicates.
 */
class HRV120ContactRoleIndexDedupeTest extends TenantTestCase
{
    public function test_migration_dedupes_before_creating_the_unique_index(): void
    {
        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);
        $this->assertTenantSchemaReady(['contact_roles', 'core_contacts']);

        $contactId = (string) Str::uuid();
        DB::table('core_contacts')->insert([
            'id' => $contactId,
            'tenant_id' => $tenantId,
            'display_name' => 'HRV120 Dup Co',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate the dirty tenant state the reviewer describes: drop the
        // current index, seed duplicates of the widened key, then run the
        // dedupe migration exactly as tenants:migrate would.
        DB::statement('DROP INDEX IF EXISTS contact_role_unique');

        foreach ([1, 2] as $ignored) {
            DB::table('contact_roles')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'role' => 'BILLING_CONTACT',
                'entity_id' => null,
                'relationship_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration = require base_path('modules/Contact/database/migrations/tenant/2026_09_23_000002_dedupe_contact_roles_before_unique_index.php');
        $migration->up();

        $this->assertSame(1, DB::table('contact_roles')
            ->where('contact_id', $contactId)
            ->where('role', 'BILLING_CONTACT')
            ->count(), 'Duplicates must be deduped before the index is created');

        $indexExists = DB::selectOne("
            SELECT 1 FROM pg_indexes WHERE tablename = 'contact_roles' AND indexname = 'contact_role_unique'
        ");
        $this->assertNotNull($indexExists, 'contact_role_unique must exist after the migration');

        // Exact duplicates are now rejected.
        $rejected = false;
        try {
            DB::table('contact_roles')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'role' => 'BILLING_CONTACT',
                'entity_id' => null,
                'relationship_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Exact duplicates must be rejected by the unique index');
    }
}
