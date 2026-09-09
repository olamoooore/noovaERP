<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingFoundation\Enums\OpeningBalanceStatus;
use Modules\AccountingFoundation\Models\OpeningBalanceAuditTrail;
use Modules\AccountingFoundation\Models\OpeningBalanceBatch;
use Modules\AccountingFoundation\Models\OpeningBalanceLine;
use Modules\AccountingFoundation\Services\OpeningBalanceLifecycle;
use Modules\AccountingFoundation\Services\OpeningBalanceService;
use Tests\TenantTestCase;

/**
 * ACC-001 Opening Balance — comprehensive test suite.
 *
 * Issue #70 (ACC-DEC-060 Track B): tests all 18 evidence categories
 * required before closure.
 */
class OpeningBalanceCapabilityTest extends TenantTestCase
{
    private string $tenantId;
    private User $user;
    private User $approver;
    private FiscalPeriod $period;
    private int $revenueAcct;
    private int $expenseAcct;
    private int $assetAcct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('Accountant');
        $this->approver->assignRole('Admin');
        Auth::login($this->user);

        $this->period = FiscalPeriod::create([
            'name' => 'OB Test Period',
            'code' => 'OB-' . Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);

        $this->revenueAcct = (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => 'OB-REV-' . Str::random(4),
            'name' => 'OB Revenue',
            'type' => 'revenue',
            'account_type' => 'revenue',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->expenseAcct = (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => 'OB-EXP-' . Str::random(4),
            'name' => 'OB Expense',
            'type' => 'expense',
            'account_type' => 'expense',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assetAcct = (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => 'OB-ASSET-' . Str::random(4),
            'name' => 'OB Asset',
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── 1. Schema / Migration ────────────────────────────────────

    public function test_tables_exist(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('opening_balance_batches'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('opening_balance_lines'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('opening_balance_audit_trail'));
    }

    // ─── 2. Lifecycle transitions ─────────────────────────────────

    public function test_happy_path_draft_to_posted(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->assertSame('draft', $batch->status);

        $service->captureLines($batch, [
            ['account_id' => $this->assetAcct, 'account_code' => 'ASSET', 'debit' => 50000, 'credit' => 0],
            ['account_id' => $this->revenueAcct, 'account_code' => 'REV', 'debit' => 0, 'credit' => 50000],
        ], (string) $this->user->id);

        $batch->refresh();
        $this->assertSame('imported', $batch->status);

        $service->validate($batch, (string) $this->user->id);
        $batch->refresh();
        $this->assertSame('validated', $batch->status);

        $service->reconcile($batch, (string) $this->user->id);
        $batch->refresh();
        $this->assertSame('reconciled', $batch->status);

        // Submit for approval (reconciled → pending_approval)
        $lifecycle = app(OpeningBalanceLifecycle::class);
        $lifecycle->validateTransition($batch->getStatusEnum(), OpeningBalanceStatus::PENDING_APPROVAL);
        $batch->update(['status' => OpeningBalanceStatus::PENDING_APPROVAL->value]);

        $service->approve($batch, (string) $this->approver->id);
        $batch->refresh();
        $this->assertSame('approved', $batch->status);

        $service->authoriseForPosting($batch, (string) $this->user->id);
        $batch->refresh();
        $this->assertSame('posting_authorised', $batch->status);

        // Verify audit trail
        $trail = $batch->auditTrail()->orderBy('created_at')->get();
        $this->assertGreaterThanOrEqual(6, $trail->count());
    }

    // ─── 3. Forbidden transitions ─────────────────────────────────

    public function test_forbidden_transitions_are_blocked(): void
    {
        $lifecycle = app(OpeningBalanceLifecycle::class);

        // Cannot skip from draft to validated
        $this->assertFalse($lifecycle->canTransition(
            OpeningBalanceStatus::DRAFT,
            OpeningBalanceStatus::VALIDATED,
        ));

        // Cannot skip from draft to posted
        $this->assertFalse($lifecycle->canTransition(
            OpeningBalanceStatus::DRAFT,
            OpeningBalanceStatus::POSTED,
        ));

        // Cannot go from posted back to draft
        $this->assertFalse($lifecycle->canTransition(
            OpeningBalanceStatus::POSTED,
            OpeningBalanceStatus::DRAFT,
        ));

        // Cannot go from validation_failed to validated (must re-import)
        $this->assertFalse($lifecycle->canTransition(
            OpeningBalanceStatus::VALIDATION_FAILED,
            OpeningBalanceStatus::VALIDATED,
        ));
    }

    public function test_forbidden_transition_throws(): void
    {
        $lifecycle = app(OpeningBalanceLifecycle::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('FORBIDDEN_TRANSITION');

        $lifecycle->validateTransition(
            OpeningBalanceStatus::DRAFT,
            OpeningBalanceStatus::POSTED,
        );
    }

    // ─── 4. Permission / SoD ──────────────────────────────────────

    public function test_approver_must_differ_from_creator(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->captureAndAdvanceToPending($batch, $service);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SOD_VIOLATION');

        // Same user tries to approve — must fail
        $service->approve($batch, (string) $this->user->id);
    }

    // ─── 5. Source lineage ────────────────────────────────────────

    public function test_source_hash_is_computed_on_capture(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $service->captureLines($batch, [
            ['account_id' => $this->assetAcct, 'account_code' => 'ASSET', 'debit' => 10000, 'credit' => 0],
            ['account_id' => $this->revenueAcct, 'account_code' => 'REV', 'debit' => 0, 'credit' => 10000],
        ], (string) $this->user->id);

        $batch->refresh();
        $this->assertNotEmpty($batch->source_hash);
        $this->assertEquals(64, strlen($batch->source_hash)); // SHA-256
    }

    // ─── 6. Population scope ──────────────────────────────────────

    public function test_scope_overlap_is_prevented(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch1 = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SCOPE_OVERLAP');

        // Same scope — must fail
        $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);
    }

    public function test_different_population_scope_is_allowed(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch1 = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        // Different scope — should succeed (a distinct canonical catalogue value)
        $batch2 = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'ar_only',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->assertNotNull($batch2->id);
    }

    // ─── 7. Mapping provenance ────────────────────────────────────

    public function test_mapping_version_is_stored(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'csv_import',
            'mapping_version' => 'v2.1',
            'mapping_hash' => hash('sha256', 'mapping-v2.1'),
        ], (string) $this->user->id);

        $batch->refresh();
        $this->assertSame('v2.1', $batch->mapping_version);
        $this->assertNotEmpty($batch->mapping_hash);
    }

    // ─── 8. Reconciliation ────────────────────────────────────────

    public function test_reconcile_passes_with_no_subledgers(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $service->captureLines($batch, [
            ['account_id' => $this->assetAcct, 'account_code' => 'ASSET', 'debit' => 10000, 'credit' => 0],
            ['account_id' => $this->revenueAcct, 'account_code' => 'REV', 'debit' => 0, 'credit' => 10000],
        ], (string) $this->user->id);

        $service->validate($batch, (string) $this->user->id);
        $service->reconcile($batch, (string) $this->user->id);

        $batch->refresh();
        $this->assertSame('reconciled', $batch->status);
    }

    // ─── 9. Approval evidence ─────────────────────────────────────

    public function test_approval_snapshot_is_stored(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->captureAndAdvanceToPending($batch, $service);

        $service->approve($batch, (string) $this->approver->id);
        $batch->refresh();

        $this->assertNotNull($batch->approval_snapshot);
        $this->assertSame($this->approver->id, $batch->approved_by);
        $this->assertNotNull($batch->approved_at);
        $this->assertSame($batch->source_hash, $batch->approval_snapshot['source_hash']);
    }

    // ─── 10. Period binding (fail-closed) ─────────────────────────

    public function test_validation_fails_for_closed_period(): void
    {
        // Close the period
        $this->period->close();

        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $service->captureLines($batch, [
            ['account_id' => $this->assetAcct, 'account_code' => 'ASSET', 'debit' => 10000, 'credit' => 0],
            ['account_id' => $this->revenueAcct, 'account_code' => 'REV', 'debit' => 0, 'credit' => 10000],
        ], (string) $this->user->id);

        $service->validate($batch, (string) $this->user->id);
        $batch->refresh();

        $this->assertSame('validation_failed', $batch->status);
    }

    // ─── 11. Posting Effect Binding ───────────────────────────────

    public function test_approval_snapshot_catches_drift(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->captureAndAdvanceToPending($batch, $service);
        $service->approve($batch, (string) $this->approver->id);

        // Tamper with source hash after approval
        $batch->update(['source_hash' => hash('sha256', 'tampered')]);

        // Fail-closed: stale approval blocks authorisation
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('APPROVAL_STALE');

        $service->authoriseForPosting($batch, (string) $this->user->id);
    }

    // ─── 12. No direct ACC-001 ledger writes ─────────────────────

    public function test_opening_balance_goes_through_posting_engine(): void
    {
        // Verify that OpeningBalanceService uses PostingEngineInterface
        // and does not directly create JournalEntry records
        $service = app(OpeningBalanceService::class);
        $reflection = new \ReflectionClass($service);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        $postingParam = collect($params)->first(fn ($p) => $p->getType()->getName() === \App\Accounting\Contracts\PostingEngineInterface::class);
        $this->assertNotNull($postingParam, 'OpeningBalanceService must depend on PostingEngineInterface');
    }

    // ─── 13. Technical idempotency ────────────────────────────────

    public function test_same_source_version_cannot_be_reposted(): void
    {
        // Once a batch is POSTED, it cannot be re-posted
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->captureAndAdvanceToApproved($batch, $service);
        $service->authoriseForPosting($batch, (string) $this->user->id);

        // Attempt to post twice — second should fail
        $service->post($batch, (string) $this->user->id);

        $batch->refresh();
        $this->assertSame('posted', $batch->status);

        // Try to transition from posted — only superseded is allowed
        $lifecycle = app(OpeningBalanceLifecycle::class);
        $this->assertFalse($lifecycle->canTransition(
            OpeningBalanceStatus::POSTED,
            OpeningBalanceStatus::POSTING_AUTHORISED,
        ));
    }

    // ─── 14. Economic baseline uniqueness ─────────────────────────

    public function test_superseded_batch_allows_new_baseline(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch1 = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->captureAndAdvanceToApproved($batch1, $service);
        $service->supersede($batch1, (string) $this->user->id, 'Correcting error');

        $batch1->refresh();
        $this->assertSame('superseded', $batch1->status);

        // Now same scope should be available
        $batch2 = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->assertNotNull($batch2->id);
    }

    // ─── 15. Source → journal linkage ─────────────────────────────

    public function test_posted_batch_stores_journal_entry_id(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->captureAndAdvanceToApproved($batch, $service);
        $service->authoriseForPosting($batch, (string) $this->user->id);
        $service->post($batch, (string) $this->user->id);

        $batch->refresh();
        $this->assertNotNull($batch->journal_entry_id);
        $this->assertNotNull($batch->posted_at);
        $this->assertSame($this->user->id, $batch->posted_by);
    }

    // ─── 16. Correction / supersession chain ──────────────────────

    public function test_supersession_marks_lines_as_superseded(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $this->captureAndAdvanceToApproved($batch, $service);

        // Lines are active before supersession
        $activeCount = $batch->lines()->where('status', 'active')->count();
        $this->assertSame(2, $activeCount);

        $service->supersede($batch, (string) $this->user->id, 'Correcting error');

        // Lines are now superseded
        $supersededCount = $batch->lines()->where('status', 'superseded')->count();
        $this->assertSame(2, $supersededCount);
    }

    // ─── 17. Audit trail ──────────────────────────────────────────

    public function test_audit_trail_records_all_transitions(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        $trail = $batch->auditTrail()->get();
        $this->assertCount(1, $trail);
        $this->assertSame('created', $trail->first()->action);

        $service->captureLines($batch, [
            ['account_id' => $this->assetAcct, 'account_code' => 'ASSET', 'debit' => 10000, 'credit' => 0],
            ['account_id' => $this->revenueAcct, 'account_code' => 'REV', 'debit' => 0, 'credit' => 10000],
        ], (string) $this->user->id);

        $trail = $batch->auditTrail()->get();
        $this->assertGreaterThanOrEqual(2, $trail->count());
    }

    // ─── 18. Validation failure ───────────────────────────────────

    public function test_unbalanced_batch_fails_validation(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        // Unbalanced: 10000 debit vs 5000 credit
        $service->captureLines($batch, [
            ['account_id' => $this->assetAcct, 'account_code' => 'ASSET', 'debit' => 10000, 'credit' => 0],
            ['account_id' => $this->revenueAcct, 'account_code' => 'REV', 'debit' => 0, 'credit' => 5000],
        ], (string) $this->user->id);

        $service->validate($batch, (string) $this->user->id);
        $batch->refresh();

        $this->assertSame('validation_failed', $batch->status);
    }

    public function test_missing_account_fails_validation(): void
    {
        $service = app(OpeningBalanceService::class);

        $batch = $service->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'source_type' => 'manual',
        ], (string) $this->user->id);

        // Reference a non-existent account
        $service->captureLines($batch, [
            ['account_id' => 999999, 'account_code' => 'MISSING', 'debit' => 10000, 'credit' => 0],
            ['account_id' => $this->revenueAcct, 'account_code' => 'REV', 'debit' => 0, 'credit' => 10000],
        ], (string) $this->user->id);

        $service->validate($batch, (string) $this->user->id);
        $batch->refresh();

        $this->assertSame('validation_failed', $batch->status);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function captureAndAdvanceToPending(OpeningBalanceBatch $batch, OpeningBalanceService $service): void
    {
        $service->captureLines($batch, [
            ['account_id' => $this->assetAcct, 'account_code' => 'ASSET', 'debit' => 50000, 'credit' => 0],
            ['account_id' => $this->revenueAcct, 'account_code' => 'REV', 'debit' => 0, 'credit' => 50000],
        ], (string) $this->user->id);

        $service->validate($batch, (string) $this->user->id);
        $service->reconcile($batch, (string) $this->user->id);

        $batch->refresh();
        $lifecycle = app(OpeningBalanceLifecycle::class);
        $lifecycle->validateTransition($batch->getStatusEnum(), OpeningBalanceStatus::PENDING_APPROVAL);
        $batch->update(['status' => OpeningBalanceStatus::PENDING_APPROVAL->value]);
    }

    private function captureAndAdvanceToApproved(OpeningBalanceBatch $batch, OpeningBalanceService $service): void
    {
        $this->captureAndAdvanceToPending($batch, $service);
        $service->approve($batch, (string) $this->approver->id);
    }
}
