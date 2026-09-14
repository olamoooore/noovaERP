<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingFoundation\Enums\OpeningBalanceStatus;
use Modules\AccountingFoundation\Models\OpeningBalanceAuditTrail;
use Modules\AccountingFoundation\Models\OpeningBalanceBatch;
use Modules\AccountingFoundation\Services\OpeningBalanceService;
use Tests\TenantTestCase;

/**
 * ACC-001 Opening Balance — Issue #70 hostile-review remediation tests.
 *
 * One test per confirmed finding (HR70-xx), providing finding → code → test
 * → runtime-evidence for the binary re-review. Does NOT close the issue
 * (HR70-12 requires an independent reviewer re-review first).
 */
class Issue70HostileReviewRemediationTest extends TenantTestCase
{
    private string $tenantId;
    private User $user;
    private User $approver;
    private FiscalPeriod $period;
    private int $assetAcct;
    private int $equityAcct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('accounting.write');
        $this->user->givePermissionTo('accounting.post');
        $this->approver->givePermissionTo('accounting.write');
        Auth::login($this->user);

        $this->period = FiscalPeriod::create([
            'name' => 'OB HR Test Period',
            'code' => 'OBR-' . Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);

        $this->assetAcct = (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => 'HR-ASSET-' . Str::random(4), 'name' => 'HR Asset',
            'type' => 'asset', 'account_type' => 'asset', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->equityAcct = (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => 'HR-EQ-' . Str::random(4), 'name' => 'HR Equity',
            'type' => 'equity', 'account_type' => 'equity', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): OpeningBalanceService
    {
        return app(OpeningBalanceService::class);
    }

    private function makeBatch(string $scope = 'full_trial_balance'): OpeningBalanceBatch
    {
        return $this->service()->createBatch([
            'tenant_id' => $this->tenantId,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => $scope,
            'source_type' => 'manual',
            'mapping_version' => 'v1',
            'mapping_hash' => hash('sha256', 'map-v1'),
        ], (string) $this->user->id);
    }

    private function addBalancedLines(OpeningBalanceBatch $batch): void
    {
        $this->service()->captureLines($batch, [
            ['account_id' => $this->assetAcct, 'account_code' => 'HR-ASSET', 'debit' => 5000, 'credit' => 0],
            ['account_id' => $this->equityAcct, 'account_code' => 'HR-EQ', 'debit' => 0, 'credit' => 5000],
        ], (string) $this->user->id);
    }

    // ── HR70-01: controlled canonical population-scope catalogue ──────────
    public function test_hr70_01_rejects_arbitrary_population_scope(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('INVALID_POPULATION_SCOPE');

        $this->makeBatch('arbitrary_scope_evading_uniqueness');
    }

    // ── HR70-02: DB-backed baseline uniqueness (concurrency-safe) ─────────
    public function test_hr70_02_db_unique_index_blocks_duplicate_baseline(): void
    {
        $key = OpeningBalanceBatch::buildScopeKey($this->tenantId, null, null, '2026-01-01', 'full_trial_balance');

        // Direct inserts (bypassing the app-level check) must hit the partial
        // unique index for the second scope-blocking row.
        DB::table('opening_balance_batches')->insert($this->rawBatchRow($key, OpeningBalanceStatus::DRAFT->value, 5001));
        $threw = false;
        try {
            DB::table('opening_balance_batches')->insert($this->rawBatchRow($key, OpeningBalanceStatus::DRAFT->value, 5002));
        } catch (\Illuminate\Database\UniqueConstraintViolationException|\Illuminate\Database\QueryException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'A second scope-blocking baseline for the same scope must violate the DB unique index');
    }

    private function rawBatchRow(string $scopeKey, string $status, int $postingDocId): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'status' => $status,
            'source_type' => 'manual',
            'source_hash' => hash('sha256', 'x'),
            'source_version' => 1,
            'cutover_date' => '2026-01-01',
            'fiscal_period_id' => $this->period->id,
            'population_scope' => 'full_trial_balance',
            'posting_document_id' => $postingDocId,
            'scope_key' => $scopeKey,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // ── HR70-04: mapping/configuration drift rejected at authorisation ─────
    public function test_hr70_04_mapping_drift_blocks_authorisation(): void
    {
        $batch = $this->makeBatch();
        $this->addBalancedLines($batch);
        $this->service()->validate($batch, (string) $this->user->id);
        $this->service()->reconcile($batch, (string) $this->user->id);
        $batch->update(['status' => OpeningBalanceStatus::PENDING_APPROVAL->value]);
        $this->service()->approve($batch, (string) $this->approver->id);

        // Drift the mapping after approval → authorisation must fail closed.
        $batch->update(['mapping_hash' => hash('sha256', 'MUTATED-mapping')]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('APPROVAL_STALE');
        $this->service()->authoriseForPosting($batch, (string) $this->user->id);
    }

    // ── HR70-05: Posting Effect Binding rejects approved≠posted divergence ─
    public function test_hr70_05_posting_effect_binding_rejects_divergence(): void
    {
        $batch = $this->makeBatch();
        $this->addBalancedLines($batch);

        // A JournalEntry whose lines do NOT reconcile to the approved effect.
        $je = JournalEntry::create([
            'entry_number' => 'HR70-05-' . Str::random(6),
            'entry_date' => '2026-01-01',
            'description' => 'Divergent effect',
            'status' => 'posted',
            'created_by' => $this->user->id,
            'posted_by' => $this->user->id,
            'posted_at' => now(),
        ]);
        // Deliberately wrong totals (debit 999 vs approved 5000).
        DB::table('journal_entry_lines')->insert([
            'journal_entry_id' => $je->id,
            'account_id' => $this->assetAcct,
            'debit' => 999, 'credit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('POSTING_EFFECT_BINDING');
        $this->service()->verifyPostingEffectBinding($batch->fresh(), $je);
    }

    // ── HR70-07: unique per-batch ACC-002 source identity (not documentId 0)
    public function test_hr70_07_distinct_batches_get_distinct_nonzero_source_ids(): void
    {
        $a = $this->makeBatch('full_trial_balance');
        $b = $this->makeBatch('ar_only');

        $this->assertNotNull($a->posting_document_id);
        $this->assertNotSame(0, (int) $a->posting_document_id, 'HR70-07: source id must not be 0');
        $this->assertNotEquals($a->posting_document_id, $b->posting_document_id,
            'Each batch must get a distinct ACC-002 source identity');
    }

    public function test_hr70_07_posted_batch_links_journal_via_unique_source_id(): void
    {
        $batch = $this->makeBatch();
        $this->addBalancedLines($batch);
        $this->driveToAuthorised($batch);
        $this->service()->post($batch, (string) $this->user->id);

        $link = JournalEntryLink::where('source_document_type', 'opening_balance')
            ->where('source_document_id', $batch->posting_document_id)
            ->first();

        $this->assertNotNull($link, 'ACC-002 linkage must be bound to the unique posting_document_id, not 0');
        $this->assertEquals($batch->journal_entry_id, $link->journal_entry_id);
    }

    // ── HR70-09: terminal-failed batch does not strand the scope ──────────
    public function test_hr70_09_failed_batch_does_not_strand_scope(): void
    {
        $batch = $this->makeBatch();
        $this->addBalancedLines($batch);
        // Force a validation failure by capturing an unbalanced set first is not
        // possible post-capture; instead drive it to a terminal failed status.
        $batch->update(['status' => OpeningBalanceStatus::VALIDATION_FAILED->value]);

        // A new batch for the SAME scope must now succeed (not SCOPE_OVERLAP).
        $replacement = $this->makeBatch();
        $this->assertNotEquals($batch->id, $replacement->id);
        $this->assertSame(OpeningBalanceStatus::DRAFT->value, $replacement->status);
    }

    // ── HR70-11: submitForApproval records the audit trail ────────────────
    public function test_hr70_11_submit_for_approval_is_audited(): void
    {
        $batch = $this->makeBatch();
        $this->addBalancedLines($batch);
        $this->service()->validate($batch, (string) $this->user->id);
        $this->service()->reconcile($batch, (string) $this->user->id);

        $this->service()->submitForApproval($batch, (string) $this->user->id);

        $audit = OpeningBalanceAuditTrail::where('batch_id', $batch->id)
            ->where('to_status', OpeningBalanceStatus::PENDING_APPROVAL->value)
            ->first();
        $this->assertNotNull($audit, 'RECONCILED→PENDING_APPROVAL must be recorded in the audit trail (HR70-11)');
        $this->assertSame(OpeningBalanceStatus::RECONCILED->value, $audit->from_status);
    }

    // ── HR70-10: posted correction reverses GL + links a successor ────────
    public function test_hr70_10_posted_correction_reverses_and_links_successor(): void
    {
        $batch = $this->makeBatch();
        $this->addBalancedLines($batch);
        $this->driveToAuthorised($batch);
        $this->service()->post($batch, (string) $this->user->id);
        $this->assertSame(OpeningBalanceStatus::POSTED->value, $batch->fresh()->status);

        $successor = $this->service()->correctPosted($batch->fresh(), (string) $this->user->id, 'correcting opening balances');

        $this->assertSame(OpeningBalanceStatus::SUPERSEDED->value, $batch->fresh()->status);
        $this->assertSame(OpeningBalanceStatus::DRAFT->value, $successor->status);
        $this->assertEquals($batch->id, $successor->supersedes_batch_id,
            'Successor must be linked to the superseded posted batch (HR70-10)');
    }

    /** drive: capture→validate→reconcile→pending→approve→authorise */
    private function driveToAuthorised(OpeningBalanceBatch $batch): void
    {
        $this->service()->validate($batch, (string) $this->user->id);
        $this->service()->reconcile($batch, (string) $this->user->id);
        $batch->update(['status' => OpeningBalanceStatus::PENDING_APPROVAL->value]);
        $this->service()->approve($batch, (string) $this->approver->id);
        $this->service()->authoriseForPosting($batch, (string) $this->user->id);
    }
}
