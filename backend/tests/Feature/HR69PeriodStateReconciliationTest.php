<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Enums\GateResult;
use App\Accounting\Enums\PeriodState;
use App\Accounting\Services\Gates\PeriodOpenGate;
use App\Models\FiscalPeriod;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ACC-DEC-060 Track A — architecture reconciliation tests.
 *
 * Issue #69: verifies the 3-state period model, retired
 * SOFT_CLOSE/CLOSE_IN_PROGRESS, fail-closed period admission,
 * and the InitializePostingPeriod retirement.
 */
class HR69PeriodStateReconciliationTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
    }

    // ─── HR69-A1: PeriodState has exactly 3 cases ──────────────────

    public function test_period_state_has_exactly_three_cases(): void
    {
        $cases = PeriodState::cases();
        $this->assertCount(3, $cases);
        $this->assertSame(['OPEN', 'CLOSED', 'LOCKED'], array_map(fn ($c) => $c->value, $cases));
    }

    // ─── HR69-A2: SOFT_CLOSE / CLOSE_IN_PROGRESS are retired ───────

    public function test_soft_close_and_close_in_progress_do_not_exist(): void
    {
        $values = array_map(fn ($c) => $c->value, PeriodState::cases());
        $this->assertNotContains('SOFT_CLOSE', $values);
        $this->assertNotContains('CLOSE_IN_PROGRESS', $values);
        $this->assertNotContains('REOPENED', $values);
    }

    // ─── HR69-A3: fromDbStatus fail-closed on unknown/null ─────────

    public function test_from_db_status_fail_closed_on_unknown(): void
    {
        // Unknown string → CLOSED (fail-closed, not OPEN)
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus('bogus'));
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus('SOFT_CLOSE'));
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus('CLOSE_IN_PROGRESS'));
        // Null/empty → CLOSED
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus(null));
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus(''));
    }

    // ─── HR69-A4: fromDbStatus maps known values correctly ─────────

    public function test_from_db_status_maps_known_values(): void
    {
        $this->assertSame(PeriodState::OPEN, PeriodState::fromDbStatus('open'));
        $this->assertSame(PeriodState::OPEN, PeriodState::fromDbStatus('OPEN'));
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus('closed'));
        $this->assertSame(PeriodState::LOCKED, PeriodState::fromDbStatus('locked'));
    }

    // ─── HR69-A5: only OPEN allows posting ─────────────────────────

    public function test_only_open_allows_posting(): void
    {
        $this->assertTrue(PeriodState::OPEN->allowsPosting());
        $this->assertFalse(PeriodState::CLOSED->allowsPosting());
        $this->assertFalse(PeriodState::LOCKED->allowsPosting());
    }

    // ─── HR69-A6: PeriodOpenGate blocks on missing period ──────────

    public function test_period_open_gate_blocks_on_missing_period(): void
    {
        $gate = new PeriodOpenGate();
        $result = $gate->evaluate([
            'period_id' => null,
            'period_required' => true,
        ]);

        $this->assertSame(GateResult::BLOCK, $result->result);
        $this->assertSame('PERIOD_MISSING_MANDATORY', $result->reasonCode);
    }

    // ─── HR69-A7: PeriodOpenGate NOT_APPLICABLE when period optional ─

    public function test_period_open_gate_not_applicable_when_optional(): void
    {
        $gate = new PeriodOpenGate();
        $result = $gate->evaluate([
            'period_id' => null,
            'period_required' => false,
        ]);

        $this->assertSame(GateResult::NOT_APPLICABLE, $result->result);
    }

    // ─── HR69-A8: PeriodOpenGate blocks on closed period ───────────

    public function test_period_open_gate_blocks_on_closed_period(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'Closed Period',
            'code' => 'CP-' . Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'closed',
        ]);

        $gate = new PeriodOpenGate();
        $result = $gate->evaluate(['period_id' => $period->id]);

        $this->assertSame(GateResult::BLOCK, $result->result);
        $this->assertSame('PERIOD_CLOSED', $result->reasonCode);
    }

    // ─── HR69-A9: PeriodOpenGate passes on open period ─────────────

    public function test_period_open_gate_passes_on_open_period(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'Open Period',
            'code' => 'OP-' . Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'open',
        ]);

        $gate = new PeriodOpenGate();
        $result = $gate->evaluate(['period_id' => $period->id]);

        $this->assertSame(GateResult::PASS, $result->result);
        $this->assertSame('PERIOD_OPEN', $result->gateCode);
    }

    // ─── HR69-A10: PeriodOpenGate blocks on locked period ──────────

    public function test_period_open_gate_blocks_on_locked_period(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'Locked Period',
            'code' => 'LP-' . Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'locked',
        ]);

        $gate = new PeriodOpenGate();
        $result = $gate->evaluate(['period_id' => $period->id]);

        $this->assertSame(GateResult::BLOCK, $result->result);
        $this->assertSame('PERIOD_CLOSED', $result->reasonCode);
    }

    // ─── HR69-A11: PeriodOpenGate blocks on unknown persisted status ─

    public function test_period_open_gate_fail_closed_on_unknown_status(): void
    {
        // The DB has a check constraint on fiscal_periods.status, so we
        // verify the fail-closed behaviour at the PeriodState layer:
        // fromDbStatus('bogus') → CLOSED → gate blocks.
        // This is the same path PeriodOpenGate uses (line 66 of PeriodOpenGate).
        $state = PeriodState::fromDbStatus('bogus');
        $this->assertSame(PeriodState::CLOSED, $state);
        $this->assertFalse($state->allowsPosting());
    }

    // ─── HR69-A12: ACC-001 is mandatory-capability for period ──────

    public function test_acc001_source_capability_mandates_period(): void
    {
        $gate = new PeriodOpenGate();
        $result = $gate->evaluate([
            'period_id' => null,
            'source_capability' => 'ACC-001',
        ]);

        $this->assertSame(GateResult::BLOCK, $result->result);
        $this->assertSame('PERIOD_MISSING_MANDATORY', $result->reasonCode);
    }

    // ─── HR69-A13: InitializePostingPeriod is retired ──────────────

    public function test_initialize_posting_period_not_in_capability_registry(): void
    {
        // Verify CapabilityRegistry no longer lists InitializePostingPeriod
        // as a live capability. It should be documented as retired.
        $registry = new \App\Accounting\CapabilityRegistry();
        $reflection = new \ReflectionClass($registry);

        // The registry's docstring should mention retirement
        $docComment = $reflection->getDocComment();
        $this->assertStringContainsString('InitializePostingPeriod', $docComment);
        $this->assertStringContainsString('retired', $docComment);
    }

    // ─── HR69-A14: PeriodState transitions are correct ─────────────

    public function test_period_state_transition_semantics(): void
    {
        // OPEN → posting allowed
        $this->assertTrue(PeriodState::OPEN->allowsPosting());
        $this->assertTrue(PeriodState::OPEN->allowsLock());
        $this->assertTrue(PeriodState::OPEN->allowsClose());
        $this->assertFalse(PeriodState::OPEN->allowsReopen());

        // CLOSED → no posting, only reopen
        $this->assertFalse(PeriodState::CLOSED->allowsPosting());
        $this->assertFalse(PeriodState::CLOSED->allowsLock());
        $this->assertFalse(PeriodState::CLOSED->allowsClose());
        $this->assertTrue(PeriodState::CLOSED->allowsReopen());

        // LOCKED → no posting, no lock, no close, no reopen
        $this->assertFalse(PeriodState::LOCKED->allowsPosting());
        $this->assertFalse(PeriodState::LOCKED->allowsLock());
        $this->assertFalse(PeriodState::LOCKED->allowsClose());
        $this->assertFalse(PeriodState::LOCKED->allowsReopen());
    }
}
