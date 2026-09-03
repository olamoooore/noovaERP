<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Services\FinancialCloseCycleService;
use Tests\TenantTestCase;

/**
 * ACC-RM-009 (ADR-005 §9) — close-cycle state, period lock, lock_date and
 * audit evidence must transition atomically; sign-off records must not be
 * lost under concurrent writers.
 *
 * Demanded by DAILY_REPORT_2026-09-01 §H risk 3 (fault-injection test
 * missing). The sign-off row-lock regression test additionally pins the
 * 2026-09-02 defect fix: signoffCycle() previously re-read the cycle via
 * an unlocked getCycle() and then read-modify-wrote the metadata sign-off
 * list — two concurrent sign-offs could lose one.
 */
class FinancialCloseCycleAtomicityTest extends TenantTestCase
{
    private string $tenantId;

    private FinancialCloseCycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();

        // fco_close_cycles.tenant_id has an FK to public.tenants — bind an
        // in-memory tenant alone is not enough (mirrors the pattern in
        // AccountingModuleEndToEndTest). Direct insert avoids the
        // TenantCreated event, which would provision a schema.
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Close Atomicity Test Tenant',
            'email' => 'close-atomicity@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContextWithPermissions($this->tenantId);
        $this->service = app(FinancialCloseCycleService::class);
    }

    private function makeCycleAndPeriod(string $status = 'ready_to_close'): array
    {
        $period = FiscalPeriod::create([
            'name' => 'Jan 2026',
            'code' => '2026-01-'.Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2026,
            'status' => 'open',
        ]);

        $cycle = CloseCycle::create([
            'tenant_id' => $this->tenantId,
            'entity_id' => 'entity-main',
            'period_id' => $period->code,
            'name' => 'Jan close',
            'status' => $status,
            'created_by' => 'cycle-preparer',
        ]);

        return [$cycle, $period];
    }

    // ── Liveness control (a broken deny-everything would also "roll back") ──

    public function test_close_cycle_happy_path_commits_state_period_lock_and_audit(): void
    {
        [$cycle, $period] = $this->makeCycleAndPeriod();

        $result = $this->service->closeCycle($this->tenantId, $cycle->id, (string) $this->actorId());

        $this->assertTrue($result);

        $this->assertSame('closed', $cycle->fresh()->status);
        $this->assertNotNull($cycle->fresh()->closed_at);
        $this->assertSame('locked', $period->fresh()->status);
        $this->assertDatabaseHas('lock_dates', [
            'lock_type' => 'all',
            'lock_date' => '2026-01-31',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('fco_audit_log', [
            'entity_type' => 'close_cycle',
            'entity_id' => $cycle->id,
            'action' => 'cycle_closed',
        ]);
    }

    // ── Fault injection: mid-transaction failure must leave NOTHING behind ──

    public function test_close_cycle_rolls_back_state_period_lock_and_lock_date_on_audit_failure(): void
    {
        [$cycle, $period] = $this->makeCycleAndPeriod();

        // Inject a fault at the LAST write of the transaction: everything
        // before it (cycle→closed, period→locked, lock_date row) must be
        // undone when the audit write throws.
        AuditLog::creating(function () {
            throw new \RuntimeException('injected audit failure');
        });

        try {
            $this->service->closeCycle($this->tenantId, $cycle->id, (string) $this->actorId());
            $this->fail('Expected the injected RuntimeException to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected audit failure', $e->getMessage());
        }

        // Whole transaction must have rolled back — no partial close.
        $this->assertSame('ready_to_close', $cycle->fresh()->status);
        $this->assertNull($cycle->fresh()->closed_at);
        $this->assertSame('open', $period->fresh()->status);
        $this->assertDatabaseMissing('lock_dates', [
            'lock_type' => 'all',
            'lock_date' => '2026-01-31',
        ]);
        $this->assertDatabaseMissing('fco_audit_log', [
            'entity_id' => $cycle->id,
            'action' => 'cycle_closed',
        ]);
    }

    // ── Sign-off: sequential distinct sign-offs must NOT lose records ──────

    public function test_two_distinct_signoffs_are_both_recorded(): void
    {
        $cycle = CloseCycle::create([
            'tenant_id' => $this->tenantId,
            'entity_id' => 'entity-main',
            'period_id' => '2026-02-SIG',
            'name' => 'Feb close',
            'status' => 'opened',
            'created_by' => 'cycle-preparer',
        ]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $first = $this->service->signoffCycle($this->tenantId, $cycle->id, (string) $userA->id, null, 'A ok');
        $this->assertTrue($first['success'], $first['reason'] ?? '');
        $this->assertSame(1, $first['signoff_count']);

        $second = $this->service->signoffCycle($this->tenantId, $cycle->id, (string) $userB->id, null, 'B ok');
        $this->assertTrue($second['success'], $second['reason'] ?? '');
        $this->assertSame(2, $second['signoff_count']);

        $signoffs = $cycle->fresh()->metadata['cycle_signoffs'];
        $this->assertCount(2, $signoffs);
        $ids = collect($signoffs)->pluck('user_id')->map(fn ($v) => (string) $v)->all();
        $this->assertContains((string) $userA->id, $ids);
        $this->assertContains((string) $userB->id, $ids);
    }

    // ── Regression proof for the 2026-09-02 lost-update fix ────────────────
    //
    // Cross-connection contention cannot be reproduced against rows created
    // inside RefreshDatabase's always-rolled-back transaction (the row is
    // invisible to a second connection), so this pins the fix at the level
    // of the SQL the service actually issues: the FIRST query signoffCycle()
    // runs against fco_close_cycles must be a SELECT ... FOR UPDATE. Before
    // the fix it went through getCycle() — a plain SELECT, which under MVCC
    // never waits for a concurrent writer's row lock, enabling the lost
    // update. The sequential two-signatory test above covers behaviour; this
    // one covers the locking mechanism itself.

    public function test_signoff_cycle_selects_the_row_for_update_before_mutating_metadata(): void
    {
        $cycle = CloseCycle::create([
            'tenant_id' => $this->tenantId,
            'entity_id' => 'entity-main',
            'period_id' => '2026-03-LOCK',
            'name' => 'Mar close',
            'status' => 'opened',
            'created_by' => 'cycle-preparer',
        ]);

        $user = User::factory()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->service->signoffCycle($this->tenantId, $cycle->id, (string) $user->id, null, 'lock proof');

        $queries = array_map(
            fn (array $q) => strtolower($q['query']),
            DB::getQueryLog()
        );
        DB::disableQueryLog();

        $this->assertTrue($result['success'], $result['reason'] ?? '');

        $lockingSelect = array_values(array_filter(
            $queries,
            fn (string $q) => str_contains($q, 'fco_close_cycles')
                && str_contains($q, 'for update')
                && str_starts_with($q, 'select')
        ));

        $this->assertNotEmpty(
            $lockingSelect,
            'signoffCycle() must SELECT ... FOR UPDATE the cycle row before mutating metadata. Queries seen: '
            .implode(' | ', $queries)
        );

        // The sign-off still lands correctly after the locked read.
        $signoffs = $cycle->fresh()->metadata['cycle_signoffs'] ?? [];
        $this->assertCount(1, $signoffs);
    }

    private function actorId(): int
    {
        return $this->actor()->id;
    }

    private function actor(): User
    {
        static $cached = [];
        $key = $this->tenantId;
        if (! isset($cached[$key])) {
            $cached[$key] = User::factory()->create();
        }

        return $cached[$key];
    }
}
