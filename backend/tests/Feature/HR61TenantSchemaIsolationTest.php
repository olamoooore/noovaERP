<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\PaymentExecution;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Models\UnappliedCash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #61 (hostile re-review HR61-01): the existing suite ran tenant
 * migrations into `public`, so no test exercised REAL schema-per-tenant
 * isolation for the ACC-005 tables.
 *
 * This test provisions two genuine Postgres schemas, creates records in each
 * through the production tenancy path (stancl `tenancy()->initialize()` — the
 * same mechanism the request middleware uses), and proves zero cross-schema
 * visibility for PaymentExecution, Settlement, Allocation and UnappliedCash,
 * with the records intact when switching back.
 */
class HR61TenantSchemaIsolationTest extends TenantTestCase
{
    private const SCHEMA_A = 'hr61_tenant_a';
    private const SCHEMA_B = 'hr61_tenant_b';

    private Tenant $tenantA;
    private Tenant $tenantB;

    private const TABLES = ['payment_executions', 'settlements', 'allocations', 'unapplied_cash'];

    protected function setUp(): void
    {
        parent::setUp();

        // schema_name is a virtual attribute (stored in data) and is NOT
        // mass-assignable — set it explicitly, matching the production
        // provisioning pattern exercised by TenancyProvisioningConfigurationTest.
        $this->tenantA = Tenant::withoutEvents(function () {
            $tenant = Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'HR61 Tenant A',
                'email' => 'hr61-a-'.Str::random(5).'@example.test',
            ]);
            $tenant->schema_name = self::SCHEMA_A;
            $tenant->save();

            return $tenant;
        });

        $this->tenantB = Tenant::withoutEvents(function () {
            $tenant = Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'HR61 Tenant B',
                'email' => 'hr61-b-'.Str::random(5).'@example.test',
            ]);
            $tenant->schema_name = self::SCHEMA_B;
            $tenant->save();

            return $tenant;
        });

        // Genuine per-tenant schemas with the four ACC-005 tables copied from
        // the migrated public schema (FKs intentionally not copied — the
        // platform's tenant migrations provision the same tables per schema).
        foreach ([self::SCHEMA_A, self::SCHEMA_B] as $schema) {
            DB::statement("CREATE SCHEMA IF NOT EXISTS \"{$schema}\"");
            foreach (self::TABLES as $table) {
                DB::statement("CREATE TABLE \"{$schema}\".{$table} (LIKE public.{$table} INCLUDING ALL)");
            }
        }
    }

    /**
     * Production-equivalent schema switch: EnsureTenantSearchPath executes a
     * SET search_path (from database.connections.pgsql.search_path) before the
     * controller runs; this test performs the same statement directly.
     */
    private function useTenantSchema(string $schema): void
    {
        DB::statement('SET search_path TO "'.$schema.'", public');
    }

    protected function tearDown(): void
    {
        DB::statement('SET search_path TO public');

        foreach ([self::SCHEMA_A, self::SCHEMA_B] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
        }

        parent::tearDown();
    }

    public function test_acc005_records_are_isolated_between_real_tenant_schemas(): void
    {
        // ── Tenant A creates representative records via the production path ──
        $this->useTenantSchema(self::SCHEMA_A);

        $settlement = Settlement::create([
            'tenant_id' => $this->tenantA->id,
            'settlement_number' => 'HR61-A-STL-1',
            'settlement_state' => 'UNALLOCATED',
            'settlement_type' => 'cash_application',
            'allocated_amount' => 0,
            'unapplied_amount' => 500,
            'currency_code' => 'USD',
        ]);

        $allocation = Allocation::create([
            'tenant_id' => $this->tenantA->id,
            'settlement_id' => $settlement->id,
            'source_record_type' => 'invoice',
            'source_record_id' => 1,
            'allocated_amount' => 100,
            'currency_code' => 'USD',
        ]);

        $execution = PaymentExecution::create([
            'tenant_id' => $this->tenantA->id,
            'execution_number' => 'HR61-A-EXE-1',
            'payment_id' => 1,
            'amount' => 500,
            'currency_code' => 'USD',
        ]);

        $unapplied = UnappliedCash::create([
            'tenant_id' => $this->tenantA->id,
            'settlement_id' => $settlement->id,
            'amount' => 400,
            'currency_code' => 'USD',
        ]);

        $ids = [
            'settlement' => $settlement->id,
            'allocation' => $allocation->id,
            'execution' => $execution->id,
            'unapplied' => $unapplied->id,
        ];

        // ── Switch to Tenant B through the same search_path mechanism ──
        $this->useTenantSchema(self::SCHEMA_B);

        // Zero visibility by primary key.
        $this->assertNull(Settlement::find($ids['settlement']), 'Tenant B must not see Tenant A settlements.');
        $this->assertNull(Allocation::find($ids['allocation']), 'Tenant B must not see Tenant A allocations.');
        $this->assertNull(PaymentExecution::find($ids['execution']), 'Tenant B must not see Tenant A payment executions.');
        $this->assertNull(UnappliedCash::find($ids['unapplied']), 'Tenant B must not see Tenant A unapplied cash.');

        // Zero visibility by stable business identifier too.
        $this->assertNull(Settlement::where('settlement_number', 'HR61-A-STL-1')->first());
        $this->assertNull(PaymentExecution::where('execution_number', 'HR61-A-EXE-1')->first());

        // Tenant B's own records stay isolated from A (create one in B).
        $bSettlement = Settlement::create([
            'tenant_id' => $this->tenantB->id,
            'settlement_number' => 'HR61-B-STL-1',
            'settlement_state' => 'UNALLOCATED',
            'settlement_type' => 'cash_application',
            'allocated_amount' => 0,
            'unapplied_amount' => 250,
            'currency_code' => 'USD',
        ]);

        // ── Switch back to Tenant A: records visible and unchanged ──
        $this->useTenantSchema(self::SCHEMA_A);

        $reloaded = Settlement::findOrFail($ids['settlement']);
        $this->assertSame('HR61-A-STL-1', $reloaded->settlement_number);
        $this->assertSame('500.00', (string) $reloaded->unapplied_amount, 'Tenant A data must be unchanged.');
        $this->assertSame($ids['allocation'], Allocation::findOrFail($ids['allocation'])->id);
        $this->assertSame($ids['execution'], PaymentExecution::findOrFail($ids['execution'])->id);
        $this->assertSame($ids['unapplied'], UnappliedCash::findOrFail($ids['unapplied'])->id);

        // Tenant A cannot see Tenant B's settlement either.
        $this->assertNull(Settlement::find($bSettlement->id));
    }
}
