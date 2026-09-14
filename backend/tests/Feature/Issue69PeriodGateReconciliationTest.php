<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\Enums\PeriodState;
use App\Accounting\PostingEngine;
use App\Accounting\Services\Gates\PeriodOpenGate;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #69 (Track A) period-gate reconciliation — confirmed code findings.
 *
 * Covers HR69-01 (mandatory Opening Balance period admission fails closed),
 * HR69-02 (unknown status is not posting-eligible), HR69-04 (null/missing
 * persisted status is not posting-eligible). The architecture/approved-source
 * reconciliation items (HR69-03/05/06/08/09) are addressed as a governance
 * matrix comment on the issue, NOT by unilaterally rewriting APPROVED docs;
 * #69 remains OPEN for independent binary re-review.
 */
class Issue69PeriodGateReconciliationTest extends TenantTestCase
{
    private string $tenantId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'T', 'email' => $this->tenantId . '@e.test',
            'plan' => 'trial', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('accounting.write');
        $this->user->givePermissionTo('accounting.post');
    }

    // HR69-02: unknown status → CLOSED (fail-closed), only explicit 'open' is OPEN
    public function test_unknown_status_is_not_posting_eligible(): void
    {
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus('bogus-state'));
        $this->assertFalse(PeriodState::fromDbStatus('bogus-state')->allowsPosting());
        $this->assertSame(PeriodState::OPEN, PeriodState::fromDbStatus('open'));
    }

    // HR69-04: null/empty persisted status → CLOSED (not OPEN)
    public function test_null_status_is_not_posting_eligible(): void
    {
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus(null));
        $this->assertSame(PeriodState::CLOSED, PeriodState::fromDbStatus(''));
        $this->assertFalse(PeriodState::fromDbStatus(null)->allowsPosting());
    }

    // HR69-04 (gate): a period whose status is not posting-open blocks posting.
    // (fiscal_periods.status is NOT NULL at the DB layer, so the null/empty
    // mapping is proven above at fromDbStatus(); here we assert the gate binds
    // status authoritatively via the enum rather than defaulting to OPEN.)
    public function test_gate_uses_period_status_enum_not_open_default(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'Locked Status Period', 'code' => 'LS-' . Str::random(6),
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'period_type' => 'yearly', 'fiscal_year' => 2026, 'status' => 'locked',
            'is_locked' => true, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = (new PeriodOpenGate())->evaluate([
            'period_id' => $period->id,
            'source_capability' => 'ACC-002',
        ]);
        $this->assertSame(\App\Accounting\Enums\GateResult::BLOCK, $result->result,
            'locked period must block posting (status read via enum, not default OPEN)');
    }

    // HR69-01: mandatory period (Opening Balance) with NO period → BLOCK, not N/A
    public function test_mandatory_period_missing_blocks_not_not_applicable(): void
    {
        $result = (new PeriodOpenGate())->evaluate([
            'source_capability' => 'ACC-001', // Opening Balance
            'period_required' => true,
            // period_id intentionally absent
        ]);
        $this->assertSame(\App\Accounting\Enums\GateResult::BLOCK, $result->result);
        $this->assertSame('PERIOD_MISSING_MANDATORY', $result->reasonCode);
    }

    // HR69-01 boundary: non-period-sensitive action with no period stays N/A
    public function test_non_mandatory_missing_period_is_not_applicable(): void
    {
        $result = (new PeriodOpenGate())->evaluate([
            'source_capability' => 'ACC-005A',
            // period_required not set, no period_id
        ]);
        $this->assertSame(\App\Accounting\Enums\GateResult::NOT_APPLICABLE, $result->result);
    }

    // HR69-01 real path: Opening Balance posting with a CLOSED period is blocked
    public function test_opening_balance_posting_bound_to_closed_period_is_blocked(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'Closed Period', 'code' => 'CP-' . Str::random(6),
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'period_type' => 'yearly', 'fiscal_year' => 2026, 'status' => 'closed',
            'is_locked' => false, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $debit = ChartOfAccount::create(['code' => 'OB69-D-' . Str::random(4), 'name' => 'D', 'type' => 'asset', 'account_type' => 'asset', 'is_active' => true]);
        $credit = ChartOfAccount::create(['code' => 'OB69-C-' . Str::random(4), 'name' => 'C', 'type' => 'equity', 'account_type' => 'equity', 'is_active' => true]);

        $result = app(PostingEngine::class)->post(new PostingRequest(
            documentType: 'opening_balance',
            documentId: 690001,
            postingDate: '2026-06-01',
            description: 'OB closed period',
            lines: [
                new PostingRequestLine('opening_balance', $debit->id, 100.0, 0),
                new PostingRequestLine('opening_balance', $credit->id, 0, 100.0),
            ],
            userId: $this->user->id,
            sourceCapability: 'ACC-001',
            requiredPermission: 'accounting.write',
            metadata: ['period_id' => $period->id, 'period_required' => true],
        ));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('PERIOD_CLOSED', $result->error ?? '');
    }
}
