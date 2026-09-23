<?php

/**
 * HR40-03 / HR40-04 / HR40-05 — Payment correction GL-reversal rollback
 * (real HTTP failure contract + effective commit boundary + zero-delta proof).
 *
 * HR40-01 / HR40-02 are preserved-pass (existing Issue40PaymentCorrectionRollbackTest
 * covers the service-level DB::transaction rollback when PostingEngine::reverse()
 * throws — those tests remain green). This file narrows to the re-review's
 * outstanding evidence:
 *
 *  - HR40-03: real HTTP failure contract — the actual
 *    POST /api/v1/corrections/payments/{id}/{reverse|refund|unapply} route
 *    through the real router->auth->tenant->permission->controller->service
 *    stack denies with a non-success response, no false success, original
 *    payment unchanged, no correction payment, no invoice/bill lifecycle
 *    mutation, no successful audit event. Attribution: every prerequisite
 *    control (auth, tenant, permission, payment resolution, correctability)
 *    succeeds before the PostingEngine reversal boundary is reached.
 *  - HR40-04: successful path + effective commit boundary — the real
 *    PostingEngine::reverse() runs (creates the real reversal journal inside
 *    its nested DB::transaction / savepoint), then a later downstream step
 *    is forced to fail. The reversal journal MUST roll back together with
 *    every other authoritative DB mutation (atomicity evidence). If the
 *    reversal journal remained committed, the test would surface the
 *    ATOMICITY gap the re-review warns about.
 *  - HR40-05: known-reconciled pre-image — capture GL control-account
 *    balance, AR subledger outstanding, payment status, original journal
 *    state BEFORE the induced failure. After the failed correction prove
 *    zero accounting delta (GL post = GL pre; subledger post = subledger
 *    pre; no reversal journal; no correction payment; no payment-status
 *    change; no correction link; no successful audit effect).
 *
 * Error-contract note: the controller maps the service RuntimeException to
 * HTTP 422 with the exception message. No approved public accounting
 * reason_code mapping exists for the PostingEngine reversal boundary —
 * flagged as ERROR-CONTRACT GAP per the re-review's "If there is no approved
 * mapping, report" rule (no new reason code invented here).
 */

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingResult;
use App\Services\DocumentLifecycleService;
use App\Models\PostingAuditLog;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentCorrectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

class HR40PaymentCorrectionAtomicityTest extends TenantTestCase
{
    private string $tenantId;

    private User $accountant;

    private Payment $payment;

    private Invoice $invoice;

    private float $glControlPre;

    private float $arSubledgerPre;

    private int $arAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'HR40 atomicity tenant',
            'email' => 'hr40-atomicity@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Accountant', 'Owner'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('Accountant');
        DB::table('user_company_access')->insert([
            'user_id' => $this->accountant->id,
            'tenant_id' => $this->tenantId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Real posted payment against a real invoice (prerequisite for
        // correction). PaymentCorrectionService.assertCorrectable checks the
        // payment is in a correctable state and that a journal entry exists.
        // Note: convergence migration added FK on payments.customer_id, so
        // we must create a Customer row before the Payment.
        $customer = \App\Models\Customer::create([
            'name' => 'HR40 Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->invoice = Invoice::create([
            'customer_name' => 'HR40 Customer',
            'invoice_number' => 'INV-HR40-001',
            'invoice_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(25)->toDateString(),
            'total' => 500.00,
            'subtotal' => 500.00,
            'amount_due' => 500.00,
            'amount_paid' => 0,
            'status' => 'sent',
            'created_by' => $this->accountant->id,
        ]);

        $cashAccount = \App\Models\ChartOfAccount::create([
            'code' => '1000-'.uniqid(),
            'name' => 'Cash-'.uniqid(),
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
        ]);
        $arAccount = \App\Models\ChartOfAccount::create([
            'code' => '1100-'.uniqid(),
            'name' => 'AR-'.uniqid(),
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
        ]);

        $journal = JournalEntry::create([
            'entry_number' => 'JE-HR40-001',
            'entry_date' => now()->subDays(5)->toDateString(),
            'description' => 'HR40 payment posting',
            'status' => 'posted',
            'type' => 'manual',
            'posted_at' => now()->subDays(5),
            'posted_by' => $this->accountant->id,
            'created_by' => $this->accountant->id,
        ]);
        $ar = JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $arAccount->id,
            'debit' => 500.00,
            'credit' => 0,
            'description' => 'AR debit',
        ]);
        $cash = JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => 500.00,
            'description' => 'Cash credit',
        ]);

        $this->payment = Payment::create([
            'payment_number' => 'PAY-HR40-001',
            'customer_id' => $customer->id,
            'invoice_id' => $this->invoice->id,
            'payment_date' => now()->subDays(2)->toDateString(),
            'amount' => 500.00,
            'method' => 'bank_transfer',
            'reference' => 'HR40-PAY-001',
            'received_by' => $this->accountant->id,
            'payment_status' => 'applied',
            'journal_entry_id' => $journal->id,
        ]);

        // Canonical subledger columns (paid_amount/balance) drive the
        // reconciliation invariant; amount_paid/amount_due are legacy mirrors.
        $this->invoice->update([
            'amount_paid' => 500.00,
            'amount_due' => 0.00,
            'paid_amount' => 500.00,
            'balance' => 0.00,
            'status' => 'paid',
        ]);

        // Known-reconciled pre-image (HR40-05): GL control balance on the
        // AR account (account_id-based — ChartOfAccount.code may not be the
        // authoritative GL key in the schema-per-tenant model).
        $this->arAccountId = $arAccount->id;
        $this->glControlPre = (float) DB::table('journal_entry_lines')
            ->where('account_id', $arAccount->id)->sum(DB::raw('debit - credit'));
        $this->arSubledgerPre = (float) $this->invoice->fresh()->balance;
    }

    private function reverseUrl(int $id, string $op): string
    {
        return "/api/v1/corrections/payments/{$id}/{$op}";
    }

    private function countReversalJournals(int $originalJournalId): int
    {
        return (int) DB::table('journal_entry_links')
            ->where('original_journal_entry_id', $originalJournalId)
            ->where('relation_type', 'reversal')
            ->count();
    }

    // ─────────────────────────────────────────────────────────────────────
    // HR40-03 — Real HTTP failure contract (reverse)
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function hr40_03_real_http_reverse_fails_without_mutation_when_posting_engine_reversal_fails(): void
    {
        // Attribution precondition — every prerequisite control must succeed.
        // The route requires accounting.post; Accountant holds it via the
        // canonical PERMISSION_MATRIX (HR38 attribution pattern).
        $this->assertTrue($this->accountant->hasPermissionTo('accounting.post'));
        $this->assertSame('applied', $this->payment->fresh()->payment_status);
        $this->assertNotNull($this->payment->fresh()->journal_entry_id);

        // Force PostingEngine::reverse() to return failure (service throws
        // RuntimeException -> outer DB::transaction rolls back).
        $stub = \Mockery::mock(PostingEngineInterface::class);
        $stub->shouldReceive('reverse')->andReturn(
            PostingResult::failure('GL reversal engine intentionally unavailable'),
        );
        app()->instance(PostingEngineInterface::class, $stub);

        // PostingAuditLog is the audit table the posting engine actually
        // writes ('full_reversal'/'partial_reversal'); audit_logs has no
        // action/result columns.
        $auditSuccessBefore = PostingAuditLog::where('action', 'like', '%reversal%')->count();

        $response = $this->actingAs($this->accountant)
            ->postJson($this->reverseUrl($this->payment->id, 'reverse'), [
                'reason_code' => 'duplicate_payment',
                'reason_text' => 'HR40 induced GL failure',
            ]);

        // Non-success HTTP response (controller maps RuntimeException to 422).
        $this->assertContains($response->status(), [422, 500],
            'Reverse must NOT report success when PostingEngine reversal fails; got '.$response->status());

        // No reversal journal.
        $this->assertSame(0, $this->countReversalJournals((int) $this->payment->journal_entry_id),
            'No reversal journal may remain on a failed reverse.');

        // Original payment unchanged (status NOT reversed, no refund_payment_id).
        $original = $this->payment->fresh();
        $this->assertSame('applied', $original->payment_status);
        $this->assertNull($original->refund_payment_id);

        // No correction payment created.
        $this->assertSame(1, Payment::count(), 'No correction payment may be created on failure.');

        // No successful audit effect.
        $this->assertSame($auditSuccessBefore, PostingAuditLog::where('action', 'like', '%reversal%')->count(),
            'No reversal audit row may be committed on failure.');

        // GL post-image = GL pre-image (zero delta) — AR control account.
        $this->assertEqualsWithDelta($this->glControlPre,
            (float) DB::table('journal_entry_lines')->where('account_id', $this->arAccountId)->sum(DB::raw('debit - credit')),
            0.01, 'GL control balance must be unchanged after failed reverse.');

        // Subledger unchanged.
        $this->assertEqualsWithDelta($this->arSubledgerPre, (float) $this->invoice->fresh()->balance,
            0.01, 'AR subledger must be unchanged after failed reverse.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // HR40-04 — Effective commit boundary + induced-failure atomicity proof
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function hr40_04_induced_failure_after_posting_engine_reversal_rolls_back_the_reversal_journal(): void
    {
        // Let the REAL PostingEngine::reverse() run (creates a real reversal
        // journal inside its nested DB::transaction / savepoint). Then force
        // a later downstream step — DocumentLifecycleService::applyPaymentReversal
        // (the subledger lifecycle update) — to throw. The outer
        // PaymentCorrectionService::reversePayment() transaction must roll back
        // the reversal journal as well, proving the same effective commit
        // boundary.
        $lifecycleMock = \Mockery::mock(DocumentLifecycleService::class);
        $lifecycleMock->shouldReceive('applyPaymentReversal')
            ->andThrow(new \RuntimeException('Induced downstream failure AFTER PostingEngine succeeded'));
        app()->instance(DocumentLifecycleService::class, $lifecycleMock);

        $service = app(PaymentCorrectionService::class);

        // Capture pre-state.
        $preJournalId = (int) $this->payment->journal_entry_id;
        $glPre = (float) DB::table('journal_entry_lines')
            ->where('account_id', $this->arAccountId)->sum(DB::raw('debit - credit'));
        $arPre = (float) $this->invoice->fresh()->balance;

        // Attempt the correction — must throw because the lifecycle step fails
        // after the PostingEngine reversal succeeds.
        try {
            $service->reversePayment($this->payment, \App\Services\CorrectionRequest::fromRequest([
                'reason_code' => 'duplicate_payment',
                'reason_text' => 'HR40 induced downstream failure',
                'user_id' => $this->accountant->id,
            ]));
            $this->fail('Induced downstream failure must abort the outer correction.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // Never swallow our own failure assertion — a silent success here
            // previously looked like an atomicity failure.
            throw $e;
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Induced downstream failure', $e->getMessage());
        }

        // Atomicity proof: the reversal journal that PostingEngine::reverse
        // committed inside its savepoint MUST have rolled back when the outer
        // correction aborted. If a reversal journal remained committed, the
        // PostingEngine commit boundary was independent of the outer
        // transaction — the ATOMICITY GAP the re-review warns about.
        $this->assertSame(0, $this->countReversalJournals($preJournalId),
            'Atomicity GAP: reversal journal remained committed after the outer correction aborted. PostingEngine::reverse() does not share the outer commit boundary.');

        // Original payment unchanged.
        $original = $this->payment->fresh();
        $this->assertSame('applied', $original->payment_status);
        $this->assertNull($original->refund_payment_id);

        // No correction payment.
        $this->assertSame(1, Payment::count(), 'Correction payment must have rolled back.');

        // Zero GL delta.
        $this->assertEqualsWithDelta($glPre,
            (float) DB::table('journal_entry_lines')->where('account_id', $this->arAccountId)->sum(DB::raw('debit - credit')),
            0.01, 'GL control balance must be unchanged after induced failure.');

        // Zero subledger delta.
        $this->assertEqualsWithDelta($arPre, (float) $this->invoice->fresh()->balance,
            0.01, 'AR subledger must be unchanged after induced failure.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // HR40-04 — Successful correction path (positive reconciliation)
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function hr40_04_successful_reverse_lands_in_expected_reconciled_post_state(): void
    {
        // No mock — let the REAL PostingEngine and lifecycle service run.
        $service = app(PaymentCorrectionService::class);

        $result = $service->reversePayment($this->payment, \App\Services\CorrectionRequest::fromRequest([
            'reason_code' => 'duplicate_payment',
            'reason_text' => 'HR40 successful reverse',
            'user_id' => $this->accountant->id,
        ]));

        $this->assertNotNull($result->payment);
        $this->assertNotNull($result->reversalJournalEntry);

        // Reconciliation post-state: payment reversed, correction payment
        // exists with reversed status, original journal has a reversal link.
        $original = $this->payment->fresh();
        $this->assertSame('reversed', $original->payment_status);
        $this->assertNotNull($original->refund_payment_id);
        $this->assertSame(1, $this->countReversalJournals((int) $original->journal_entry_id));

        // AR subledger restored (outstanding back on the invoice).
        $fresh = $this->invoice->fresh();
        $this->assertGreaterThan($this->arSubledgerPre, (float) $fresh->balance,
            'AR outstanding must increase after successful reverse (invoice re-opened).');
        $this->assertSame(0.0, round((float) $fresh->paid_amount, 2), 'paid_amount must drop back to 0.');
    }
}
