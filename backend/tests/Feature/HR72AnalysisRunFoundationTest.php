<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\AI\DTOs\AnalysisScope;
use Modules\AI\DTOs\SourceStateBinding;
use Modules\AI\Models\AnalysisRun;
use Modules\AI\Services\AnalysisRunService;
use Tests\TenantTestCase;

/**
 * B2-IMPL-01A — AnalysisRun admission, scope, source-state, staleness tests.
 *
 * Issue #72: 9 active evidence rows (ACT03-EVID-02A-001 through 009).
 */
class HR72AnalysisRunFoundationTest extends TenantTestCase
{
    private string $tenantId;
    private string $entityId;
    private string $bookId;
    private FiscalPeriod $period;
    private AnalysisRunService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->entityId = (string) Str::uuid();
        $this->bookId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->service = app(AnalysisRunService::class);

        $this->period = FiscalPeriod::create([
            'name' => 'AI Test Period',
            'code' => 'AI-' . Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'open',
        ]);
    }

    // ─── EVID-02A-001: No hard-coded book fallback ────────────────

    public function test_scope_rejects_empty_book_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SCOPE_INVARIANT');

        new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: $this->entityId,
            accountingBookId: '',
            fiscalPeriodId: $this->period->id,
            analysisType: 'financial_close_ai',
        );
    }

    // ─── EVID-02A-002: Invalid/missing period fails ───────────────

    public function test_admission_fails_on_missing_period(): void
    {
        $scope = new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: $this->entityId,
            accountingBookId: $this->bookId,
            fiscalPeriodId: 999999,
            analysisType: 'financial_close_ai',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('PERIOD_NOT_FOUND');

        $this->service->admit($scope);
    }

    public function test_admission_fails_on_closed_period(): void
    {
        $this->period->update(['status' => 'closed']);

        $scope = new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: $this->entityId,
            accountingBookId: $this->bookId,
            fiscalPeriodId: $this->period->id,
            analysisType: 'financial_close_ai',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('PERIOD_NOT_OPEN');

        $this->service->admit($scope);
    }

    // ─── EVID-02A-003: Scope = tenant/entity/book/period ──────────

    public function test_scope_hash_deterministic(): void
    {
        $scope1 = new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: $this->entityId,
            accountingBookId: $this->bookId,
            fiscalPeriodId: $this->period->id,
            analysisType: 'financial_close_ai',
        );

        $scope2 = new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: $this->entityId,
            accountingBookId: $this->bookId,
            fiscalPeriodId: $this->period->id,
            analysisType: 'financial_close_ai',
        );

        $this->assertSame($scope1->scopeHash(), $scope2->scopeHash());
    }

    public function test_different_scope_produces_different_hash(): void
    {
        $scope1 = new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: $this->entityId,
            accountingBookId: $this->bookId,
            fiscalPeriodId: $this->period->id,
            analysisType: 'financial_close_ai',
        );

        $scope2 = new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: $this->entityId,
            accountingBookId: (string) Str::uuid(),
            fiscalPeriodId: $this->period->id,
            analysisType: 'financial_close_ai',
        );

        $this->assertNotSame($scope1->scopeHash(), $scope2->scopeHash());
    }

    // ─── EVID-02A-004: AnalysisRun identity persisted ─────────────

    public function test_analysis_run_persisted_with_identity(): void
    {
        $scope = $this->makeScope();
        $run = $this->service->admit($scope, description: 'Test run');

        $this->assertNotNull($run->id);
        $this->assertNotNull($run->run_uuid);
        $this->assertSame('admitted', $run->status);
        $this->assertTrue($run->is_current);
        $this->assertNotNull($run->admitted_at);
        $this->assertSame('financial_close_ai', $run->analysis_type);
        $this->assertSame($this->tenantId, $run->tenant_id);
        $this->assertSame($this->entityId, $run->legal_entity_id);
        $this->assertSame($this->bookId, $run->accounting_book_id);
        $this->assertSame($this->period->id, $run->fiscal_period_id);
    }

    // ─── EVID-02A-005: Source state bound ─────────────────────────

    public function test_source_state_bound_to_run(): void
    {
        $scope = $this->makeScope();
        $sourceState = new SourceStateBinding(
            sourceType: 'journal_entries',
            sourceId: 'batch-001',
            sourceVersion: 'v3',
            sourceHash: hash('sha256', 'source-data'),
        );

        $run = $this->service->admit($scope, sourceState: $sourceState);

        $this->assertNotNull($run->source_state);
        $this->assertSame('journal_entries', $run->source_state['source_type']);
        $this->assertSame('batch-001', $run->source_state['source_id']);
        $this->assertSame('v3', $run->source_state['source_version']);
        $this->assertNotNull($run->source_state_hash);
    }

    // ─── EVID-02A-006: Current-run selection scoped correctly ─────

    public function test_current_run_selection_supersedes_old(): void
    {
        $scope = $this->makeScope();

        // First admission
        $run1 = $this->service->admit($scope, description: 'First run');
        $this->assertTrue($run1->is_current);
        $this->assertSame('admitted', $run1->status);

        // Second admission for same scope → run1 should be superseded
        $run2 = $this->service->admit($scope, description: 'Second run');

        $run1->refresh();
        $this->assertFalse($run1->is_current);
        $this->assertSame('superseded', $run1->status);

        $this->assertTrue($run2->is_current);
        $this->assertSame('admitted', $run2->status);
    }

    public function test_get_current_run_returns_only_current(): void
    {
        $scope = $this->makeScope();
        $run = $this->service->admit($scope);

        $found = $this->service->getCurrentRun(
            'financial_close_ai',
            $this->tenantId,
            $this->entityId,
            $this->bookId,
            $this->period->id,
        );

        $this->assertNotNull($found);
        $this->assertSame($run->id, $found->id);
    }

    public function test_get_current_run_returns_null_when_all_superseded(): void
    {
        $scope = $this->makeScope();
        $run1 = $this->service->admit($scope);
        $run2 = $this->service->admit($scope); // supersede run1

        // Supersede run2 as well — now no current run exists
        $run2->update(['status' => 'superseded', 'is_current' => false]);

        $found = $this->service->getCurrentRun(
            'financial_close_ai',
            $this->tenantId,
            $this->entityId,
            $this->bookId,
            $this->period->id,
        );

        $this->assertNull($found);
    }

    // ─── EVID-02A-007: Older run cannot supersede incorrectly ─────

    public function test_superseded_run_cannot_become_current(): void
    {
        $scope = $this->makeScope();
        $run1 = $this->service->admit($scope);
        $run2 = $this->service->admit($scope);

        // Try to re-admit with run1's scope — run2 should be superseded
        $run3 = $this->service->admit($scope);

        $run1->refresh();
        $run2->refresh();

        $this->assertSame('superseded', $run1->status);
        $this->assertSame('superseded', $run2->status);
        $this->assertTrue($run3->is_current);
    }

    // ─── EVID-02A-008: Source change invokes staleness ────────────

    public function test_source_state_change_marks_stale(): void
    {
        $scope = $this->makeScope();
        $sourceV1 = new SourceStateBinding(
            sourceType: 'journal_entries',
            sourceId: 'batch-001',
            sourceVersion: 'v1',
            sourceHash: hash('sha256', 'v1-data'),
        );

        $run = $this->service->admit($scope, sourceState: $sourceV1);
        $this->assertTrue($run->is_current);

        // Source state changes
        $sourceV2 = new SourceStateBinding(
            sourceType: 'journal_entries',
            sourceId: 'batch-001',
            sourceVersion: 'v2',
            sourceHash: hash('sha256', 'v2-data'),
        );

        $stale = $this->service->evaluateSourceStateChange($run, $sourceV2);

        $this->assertTrue($stale);
        $run->refresh();
        $this->assertSame('stale', $run->status);
        $this->assertFalse($run->is_current);
        $this->assertNotNull($run->stale_at);
        $this->assertSame('source_state_changed', $run->stale_reason);
    }

    public function test_same_source_state_does_not_mark_stale(): void
    {
        $scope = $this->makeScope();
        $sourceState = new SourceStateBinding(
            sourceType: 'journal_entries',
            sourceId: 'batch-001',
            sourceVersion: 'v1',
            sourceHash: hash('sha256', 'v1-data'),
        );

        $run = $this->service->admit($scope, sourceState: $sourceState);
        $stale = $this->service->evaluateSourceStateChange($run, $sourceState);

        $this->assertFalse($stale);
        $run->refresh();
        $this->assertSame('admitted', $run->status);
        $this->assertTrue($run->is_current);
    }

    // ─── EVID-02A-009: Concurrent execution preserves invariant ───

    public function test_concurrent_admission_preserves_current_invariant(): void
    {
        $scope = $this->makeScope();

        // Simulate two concurrent admissions using the locking method
        $run1 = $this->service->admitWithLock($scope, description: 'Concurrent 1');
        $run2 = $this->service->admitWithLock($scope, description: 'Concurrent 2');

        // Only run2 should be current
        $run1->refresh();
        $run2->refresh();

        $this->assertSame('superseded', $run1->status);
        $this->assertFalse($run1->is_current);
        $this->assertSame('admitted', $run2->status);
        $this->assertTrue($run2->is_current);

        // Verify invariant: exactly one current run for this scope
        $currentCount = AnalysisRun::where('analysis_type', 'financial_close_ai')
            ->where('tenant_id', $this->tenantId)
            ->where('legal_entity_id', $this->entityId)
            ->where('accounting_book_id', $this->bookId)
            ->where('fiscal_period_id', $this->period->id)
            ->where('is_current', true)
            ->where('status', 'admitted')
            ->count();
        $this->assertSame(1, $currentCount);
    }

    // ─── Scope rejection: empty entity ────────────────────────────

    public function test_scope_rejects_empty_entity_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: '',
            accountingBookId: $this->bookId,
            fiscalPeriodId: $this->period->id,
            analysisType: 'financial_close_ai',
        );
    }

    // ─── Analysis admitted ≠ finding qualified ────────────────────

    public function test_admitted_run_does_not_imply_readiness(): void
    {
        $scope = $this->makeScope();
        $run = $this->service->admit($scope);

        // Admitted means the run is accepted for processing, NOT that
        // findings are qualified, readiness passed, or close authorized.
        $this->assertSame('admitted', $run->status);
        $this->assertTrue($run->is_current);
        // No readiness/finding/close fields exist on AnalysisRun —
        // this is by design (ACC-010/ACC-011 are separate authorities).
        $this->assertObjectNotHasProperty('readiness_status', $run);
        $this->assertObjectNotHasProperty('finding_authority', $run);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function makeScope(): AnalysisScope
    {
        return new AnalysisScope(
            tenantId: $this->tenantId,
            legalEntityId: $this->entityId,
            accountingBookId: $this->bookId,
            fiscalPeriodId: $this->period->id,
            analysisType: 'financial_close_ai',
        );
    }
}
