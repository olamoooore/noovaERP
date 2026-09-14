<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\AccountingFinding;
use App\Models\AssuranceBlockingPolicy;
use Illuminate\Support\Str;
use Modules\FinancialClose\Models\CloseCycle;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #39 — Financial Close readiness uses the canonical
 * AccountingFinding model with a defined blocking policy.
 */
class Issue39FinancialCloseReadinessTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        // fco_close_cycles has an FK to public.tenants — ensure the row exists.
        \Illuminate\Support\Facades\DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // HR39-01: the blocking decision is now resolved by the canonical
        // ACC-010 authority (AssuranceGate -> AssuranceBlockingPolicy). Seed
        // an active tenant-scoped policy so the gate has a policy to apply:
        // wildcard category, HIGH threshold, BLOCK mode, action_scope covering
        // FINANCIAL_CLOSE_ACTION (PERIOD_CLOSE). Without a policy, AssuranceGate
        // returns PASS (ADR-005 §39.1: a finding does not block merely because
        // it exists) — which is the correct canonical behavior.
        AssuranceBlockingPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'policy_name' => 'HR39 readiness test policy',
            'policy_version' => '1.0',
            'finding_category' => '*',
            'assertion_level' => 'STANDARD',
            'severity_threshold' => 'HIGH',
            'risk_threshold' => null,
            'action_scope' => ['PERIOD_CLOSE'],
            'block_mode' => 'BLOCK',
            'effective_from' => null,
            'effective_to' => null,
            'status' => 'active',
        ]);
    }

    private function createCycle(string $entityId = 'entity-001'): CloseCycle
    {
        return CloseCycle::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => $entityId,
            'period_id' => 'period-001',
            'name' => 'Test Cycle',
            'status' => 'opened',
            'created_by' => 'system',
            'metadata' => ['cycle_signoffs' => []],
        ]);
    }

    public function test_critical_finding_blocks_readiness(): void
    {
        $cycle = $this->createCycle();

        AccountingFinding::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => $cycle->entity_id,
            'finding_type' => 'control_weakness',
            'severity' => 'critical',
            'status' => 'open',
            'source_type' => 'close_cycle',
            'source_id' => $cycle->id,
            'title' => 'Critical: SoD breach',
            'description' => 'Preparer = Approver',
        ]);

        $service = app(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);
        $evaluation = $service->evaluateReadiness($this->tenantUuid, $cycle->id);

        $this->assertFalse($evaluation['ready_to_close']);
        $this->assertSame(1, $evaluation['blocking_findings']);
        $this->assertSame(1, $evaluation['open_findings']);
        // HR39-01: the blocking decision is now consumed from the canonical
        // ACC-010 authority — not a locally reproduced policy. The returned
        // blocking_policy identifies the resolver and its governed decision
        // reason (ASSURANCE_BLOCKED), not a hard-coded severity/status rule.
        $this->assertSame('ACC-010', $evaluation['blocking_policy']['authority']);
        $this->assertSame('PERIOD_CLOSE', $evaluation['blocking_policy']['action']);
        $this->assertSame('ASSURANCE_BLOCKED', $evaluation['blocking_policy']['decision_reason']);
    }

    public function test_low_finding_does_not_block_readiness(): void
    {
        $cycle = $this->createCycle();

        AccountingFinding::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => $cycle->entity_id,
            'finding_type' => 'missing_support',
            'severity' => 'low',
            'status' => 'open',
            'source_type' => 'close_cycle',
            'source_id' => $cycle->id,
            'title' => 'Low severity: missing attachment',
            'description' => 'Just a minor issue',
        ]);

        $service = app(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);
        $evaluation = $service->evaluateReadiness($this->tenantUuid, $cycle->id);

        $this->assertSame(1, $evaluation['open_findings']);
        $this->assertSame(0, $evaluation['blocking_findings']);
        // No incomplete tasks/escalations and no signoffs required for the test
        // setup (no signoffs present) → still not ready, but not because of
        // the finding. Confirm the finding is NOT the blocker.
        $this->assertSame(0, $evaluation['blocking_findings']);
    }

    public function test_resolved_finding_does_not_block_readiness(): void
    {
        $cycle = $this->createCycle();

        AccountingFinding::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => $cycle->entity_id,
            'finding_type' => 'control_weakness',
            'severity' => 'critical',
            'status' => 'resolved',
            'source_type' => 'close_cycle',
            'source_id' => $cycle->id,
            'title' => 'Resolved finding',
            'description' => 'Already fixed',
            'resolved_at' => now(),
        ]);

        $service = app(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);
        $evaluation = $service->evaluateReadiness($this->tenantUuid, $cycle->id);

        $this->assertSame(0, $evaluation['blocking_findings']);
        $this->assertSame(0, $evaluation['open_findings']);
    }

    public function test_entity_wide_high_finding_blocks_readiness(): void
    {
        $cycle = $this->createCycle('entity-A');

        AccountingFinding::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => 'entity-A',
            'finding_type' => 'anomaly',
            'severity' => 'high',
            'status' => 'investigating',
            'source_type' => 'journal_entry',
            'source_id' => Str::uuid()->toString(),
            'title' => 'High severity balance anomaly',
            'description' => '5000-account variance',
        ]);

        $service = app(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);
        $evaluation = $service->evaluateReadiness($this->tenantUuid, $cycle->id);

        $this->assertSame(1, $evaluation['blocking_findings']);
    }

    /**
     * AC5 — A period-close attempt blocked by an open finding produces
     * a canonical negative-state (closeCycle returns false, cycle stays
     * `opened`), not a silent pass-through.
     *
     * To exercise this, we attempt to close a cycle that has blocking
     * findings. The cycle was never marked ready_to_close because the
     * blocking findings prevent that transition (verified by
     * test_critical_finding_blocks_readiness above).
     */
    public function test_close_attempt_with_open_finding_is_blocked(): void
    {
        $cycle = $this->createCycle();

        AccountingFinding::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => $cycle->entity_id,
            'finding_type' => 'control_weakness',
            'severity' => 'critical',
            'status' => 'open',
            'source_type' => 'close_cycle',
            'source_id' => $cycle->id,
            'title' => 'Critical: unreviewed adjustment',
            'description' => 'Material adjustment pending review',
        ]);

        $service = app(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);

        // Confirm readiness is blocked BEFORE attempting close
        $evaluation = $service->evaluateReadiness($this->tenantUuid, $cycle->id);
        $this->assertFalse(
            $evaluation['ready_to_close'],
            'Readiness must be false with a blocking finding'
        );
        $this->assertSame(1, $evaluation['blocking_findings']);

        // Attempt to close the cycle — must return false
        $closed = $service->closeCycle($this->tenantUuid, $cycle->id, 'user-001');

        $this->assertFalse(
            $closed,
            'closeCycle must return false (canonical block) when cycle is not ready_to_close'
        );

        // Cycle status must remain 'opened' — no silent pass-through
        $cycle->refresh();
        $this->assertSame(
            'opened',
            $cycle->status,
            'Cycle must remain opened after a blocked close attempt'
        );
    }
}
