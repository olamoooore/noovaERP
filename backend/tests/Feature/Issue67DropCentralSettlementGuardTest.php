<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue noovaerp/NoovaERP#67 — central settlement-table drop guard
 * regressed under partial tenants:migrate roll-out.
 *
 * Simulates a partial roll-out: the new tenant migration creates per-
 * tenant copies of the ACC-005 batch tables; the central drop guard
 * must COMPLETENESS-CHECK against every active tenant schema before
 * dropping the central copy. A partial roll-out (table present in
 * some but not all tenant schemas) must:
 *   1. leave the central public.x tables intact (no silent outage);
 *   2. surface the mismatch as a loud RuntimeException so the deploy
 *      fails fast and visibly rather than silently degrading tenants.
 */
class Issue67DropCentralSettlementGuardTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TenantTestCase::bindTenantContext() only sets tenancy state
        // (search_path); it does NOT create the schema. The migration
        // guard queries information_schema for tenant schemas, so we
        // must create them explicitly.
        $first = 'test-tenant';
        $second = '11111111-1111-1111-1111-111111111111';
        DB::statement('CREATE SCHEMA IF NOT EXISTS "' . $first . '"');
        DB::statement('CREATE SCHEMA IF NOT EXISTS "' . $second . '"');

        // Register the second tenant so the migration's expected set
        // includes both schemas.
        if (! DB::table('public.tenants')->where('id', $second)->exists()) {
            DB::table('public.tenants')->insert([
                'id' => $second,
                'name' => 'Issue67 Second Tenant',
                'email' => 'second@issue67.test',
                'created_at' => now(),
                'updated_at' => now(),
                'data' => json_encode(['created_at' => (string) now(), 'updated_at' => (string) now()]),
            ]);
        }
    }

    protected function tearDown(): void
    {
        // Clean up: drop the second tenant row + schema we created.
        $second = '11111111-1111-1111-1111-111111111111';
        DB::table('public.tenants')->where('id', $second)->delete();
        // The schema's tables were only created inside the test; the
        // schema itself can be left in place (test isolation between
        // runs is handled by RefreshDatabase).
        parent::tearDown();
    }

    public function test_guard_refuses_to_drop_central_table_on_partial_rollout(): void
    {
        $tables = ['payment_executions', 'settlements', 'allocations', 'unapplied_cash'];

        // 1. Ensure public.<t> exists (representing the pre-migration state).
        foreach ($tables as $t) {
            DB::statement(sprintf(
                'CREATE TABLE IF NOT EXISTS public.%s (id bigserial primary key, tenant_id varchar(255) not null)',
                $t
            ));
        }

        // 2. Provision the "complete" half: create the four tables under
        // the first tenant schema only. The second tenant schema stays
        // empty — simulates a partial tenants:migrate roll-out.
        foreach (['test-tenant'] as $schema) {
            foreach ($tables as $t) {
                DB::statement(sprintf(
                    'CREATE TABLE IF NOT EXISTS "%s".%s (id bigserial primary key, tenant_id varchar(255) not null)',
                    $schema,
                    $t
                ));
            }
        }

        // 3. Sentinel: assert the central table exists immediately after
        // the failure. (No insert needed — payment_executions has a NOT
        // NULL payment_id that varies across migrations; "table still
        // exists" is sufficient evidence the drop was skipped.)
        $existsBefore = (bool) DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'payment_executions'"
        );
        $this->assertTrue($existsBefore, 'precondition: central public.payment_executions must exist before running the migration');

        $migration = include base_path('database/migrations/2026_09_04_000003_drop_central_settlement_batch_tables.php');

        $threw = false;
        try {
            $migration->up();
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('incomplete tenants:migrate roll-out', $e->getMessage());
            $this->assertStringContainsString('11111111-1111-1111-1111-111111111111', $e->getMessage());
        }
        $this->assertTrue($threw, 'migration must fail-loud on partial roll-out');

        // 4. Central tables must still be intact (no silent drop).
        foreach ($tables as $t) {
            $exists = (bool) DB::selectOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
                [$t]
            );
            $this->assertTrue($exists, "central public.{$t} must be preserved on partial roll-out");
        }
    }

    /**
     * Issue #67 (HR67-01/02): the guard must resolve schema names through the
     * canonical tenancy resolver (Tenant::getSchemaName()), not assume
     * `schema == tenant id`. A tenant provisioned with a custom schema_name is
     * checked against THAT schema.
     */
    public function test_guard_resolves_custom_schema_names_not_tenant_ids(): void
    {
        $tables = ['payment_executions', 'settlements', 'allocations', 'unapplied_cash'];

        $customTenantId = '33333333-3333-3333-3333-333333333333';
        $customSchema = 'issue67_custom_schema';

        // Provision a tenant whose schema name differs from its id.
        // withoutEvents: saving a tenant normally fires TenantCreated and the
        // provisioning pipeline (migrate the new schema) — not wanted here.
        \App\Models\Tenant::withoutEvents(function () use ($customTenantId, $customSchema) {
            $tenant = new \App\Models\Tenant;
            $tenant->id = $customTenantId;
            $tenant->name = 'Issue67 Custom Schema Tenant';
            $tenant->email = 'custom@issue67.test';
            $tenant->schema_name = $customSchema;
            $tenant->save();
        });

        DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$customSchema.'"');
        DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$customTenantId.'"');

        foreach ($tables as $t) {
            DB::statement(sprintf('CREATE TABLE IF NOT EXISTS public.%s (id bigserial primary key, tenant_id varchar(255) not null)', $t));

            // Every EXPECTED schema (resolved) has the tables...
            foreach (['test-tenant', '11111111-1111-1111-1111-111111111111', $customSchema] as $schema) {
                DB::statement(sprintf('CREATE TABLE IF NOT EXISTS "%s".%s (id bigserial primary key, tenant_id varchar(255) not null)', $schema, $t));
            }

            // ...while the ID-named schema for the custom tenant deliberately
            // does NOT (it is not the resolved schema and must not be required).
            DB::statement(sprintf('DROP TABLE IF EXISTS "%s".%s', $customTenantId, $t));
        }

        $migration = include base_path('database/migrations/2026_09_04_000003_drop_central_settlement_batch_tables.php');

        // Complete from the resolver's perspective -> drops cleanly.
        $migration->up();

        foreach ($tables as $t) {
            $exists = (bool) DB::selectOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
                [$t]
            );
            $this->assertFalse($exists, "central public.{$t} must be dropped when every RESOLVED schema has its copy");
        }

        // Now the inverse: the resolved schema is missing its copy while the
        // id-named schema has it — the guard must still refuse and name the
        // resolved schema, proving it did not fall back to the tenant id.
        foreach ($tables as $t) {
            DB::statement(sprintf('CREATE TABLE IF NOT EXISTS public.%s (id bigserial primary key, tenant_id varchar(255) not null)', $t));
            DB::statement(sprintf('DROP TABLE IF EXISTS "%s".%s', $customSchema, $t));
            DB::statement(sprintf('CREATE TABLE IF NOT EXISTS "%s".%s (id bigserial primary key, tenant_id varchar(255) not null)', $customTenantId, $t));
        }

        $threw = false;
        try {
            $migration->up();
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString($customSchema, $e->getMessage(), 'The guard must name the RESOLVED schema.');
            $this->assertStringNotContainsString($customTenantId, $e->getMessage(), 'The guard must not fall back to the tenant id.');
        }
        $this->assertTrue($threw, 'A missing copy in the resolved schema must fail closed.');
    }

    public function test_guard_drops_central_table_on_complete_rollout(): void
    {
        $tables = ['payment_executions', 'settlements', 'allocations', 'unapplied_cash'];

        // 1. Create central tables.
        foreach ($tables as $t) {
            DB::statement(sprintf(
                'CREATE TABLE IF NOT EXISTS public.%s (id bigserial primary key, tenant_id varchar(255) not null)',
                $t
            ));
        }

        // 2. Create per-tenant copy in BOTH schemas — complete roll-out.
        foreach (['test-tenant', '11111111-1111-1111-1111-111111111111'] as $schema) {
            foreach ($tables as $t) {
                DB::statement(sprintf(
                    'CREATE TABLE IF NOT EXISTS "%s".%s (id bigserial primary key, tenant_id varchar(255) not null)',
                    $schema,
                    $t
                ));
            }
        }

        $migration = include base_path('database/migrations/2026_09_04_000003_drop_central_settlement_batch_tables.php');
        $migration->up();

        // All four central tables must now be gone.
        foreach ($tables as $t) {
            $exists = (bool) DB::selectOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
                [$t]
            );
            $this->assertFalse($exists, "central public.{$t} should be dropped on complete roll-out");
        }

        // Tenant copies still present.
        foreach (['test-tenant', '11111111-1111-1111-1111-111111111111'] as $schema) {
            foreach ($tables as $t) {
                $exists = (bool) DB::selectOne(
                    'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                    [$schema, $t]
                );
                $this->assertTrue($exists, "tenant.{$schema}.{$t} must remain");
            }
        }
    }
}
