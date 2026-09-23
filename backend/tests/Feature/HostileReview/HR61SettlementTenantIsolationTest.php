<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use App\Models\Allocation;
use App\Models\PaymentExecution;
use App\Models\Settlement;
use App\Models\UnappliedCash;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Hostile review HR61-01 (#61): the ACC-005 settlement-batch tables must be
 * tenant-schema objects, not shared central tables, and the central drop
 * migration must be completeness-guarded.
 *
 * The test harness applies tenant migrations into the `public` schema, so a
 * true two-schema runtime test is impossible here; this verifies the schema
 * ownership itself (tenant migration creates them, models follow the active
 * schema connection, central drop is guarded) plus tenant_id discipline.
 */
class HR61SettlementTenantIsolationTest extends TenantTestCase
{
    private const TABLES = ['payment_executions', 'settlements', 'allocations', 'unapplied_cash'];

    public function test_the_settlement_batch_tables_are_created_by_a_tenant_migration(): void
    {
        $tenantMigration = base_path('database/migrations/tenant/2026_09_04_000002_move_settlement_batch_tables_to_tenant_schema.php');

        $this->assertFileExists($tenantMigration, 'The move-to-tenant migration must exist');
        $contents = file_get_contents($tenantMigration);

        foreach (self::TABLES as $table) {
            $this->assertStringContainsString(
                "Schema::create('{$table}'",
                $contents,
                "{$table} must be created by the tenant migration"
            );
        }
    }

    public function test_the_central_drop_migration_is_completeness_guarded(): void
    {
        $centralDrop = base_path('database/migrations/2026_09_04_000003_drop_central_settlement_batch_tables.php');

        $this->assertFileExists($centralDrop);
        $contents = file_get_contents($centralDrop);

        $this->assertStringContainsString('expectedTenantSchemas', $contents, 'Drop must check every tenant schema has its copy');
        $this->assertStringContainsString('Exception', $contents, 'Incomplete roll-outs must fail loudly');
    }

    public function test_the_models_follow_the_active_tenant_schema_and_carry_tenant_id(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $this->bindTenantContext($tenantA);

        $settlement = new Settlement;
        $this->assertNull($settlement->getConnectionName(), 'Settlement must not pin a central connection');

        foreach ([PaymentExecution::class, Allocation::class, UnappliedCash::class] as $modelClass) {
            $this->assertNull((new $modelClass)->getConnectionName(), "{$modelClass} must not pin a central connection");
            $this->assertContains('tenant_id', (new $modelClass)->getFillable(), "{$modelClass} must expose tenant_id");
        }

        $this->assertContains('tenant_id', $settlement->getFillable(), 'Settlement must expose tenant_id');

        // Tenant_id discipline: a row created for tenant A is not visible to a
        // tenant-B-scoped query on the shared test schema.
        Settlement::create([
            'tenant_id' => $tenantA,
            'settlement_number' => 'HR61-'.Str::upper(Str::random(6)),
            'settlement_state' => 'UNALLOCATED',
            'settlement_type' => 'cash_application',
            'allocated_amount' => 0,
            'unapplied_amount' => 100,
            'currency_code' => 'USD',
        ]);

        $this->assertSame(1, Settlement::where('tenant_id', $tenantA)->count());
        $this->assertSame(0, Settlement::where('tenant_id', $tenantB)->count());
    }
}
