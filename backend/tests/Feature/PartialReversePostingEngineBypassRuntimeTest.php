<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Http\Middleware\EnsureAccountingReverseAccess;
use App\Http\Middleware\EnsureAccountingWriteAccess;
use App\Http\Middleware\EnsureEntityAccess;
use App\Http\Middleware\ResolveEntityContext;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\PostingAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * RUNTIME VERIFICATION — CLAIM B
 *
 * Prior static-analysis claim: JournalEntriesController::partialReverse()
 * bypasses PostingEngine, writes directly to journal_entries/journal_entry_lines
 * WITHOUT an explicit DB::transaction() wrapper and WITHOUT creating a
 * PostingAuditLog or JournalEntryLink row. A prior reviewer speculated
 * (without testing) that the lack of an explicit transaction might interact
 * badly with the DEFERRABLE INITIALLY DEFERRED constraint trigger that
 * enforces debit=credit balance on journal_entry_lines.
 *
 * This test class deliberately does NOT use the standard RefreshDatabase
 * per-test transaction wrapper (it overrides beginDatabaseTransaction() to
 * be a no-op). That wrapper would place the ENTIRE test inside one outer
 * transaction that is rolled back at tearDown and never truly COMMITs -
 * meaning a DEFERRED constraint trigger (which fires at real COMMIT) would
 * never actually fire during the test, silently hiding the exact behavior
 * this test exists to observe. Real commits happen here, matching
 * production request behavior. All test data is cleaned up manually in
 * tearDown() via TRUNCATE.
 */
class PartialReversePostingEngineBypassRuntimeTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $user;

    /**
     * Override: skip RefreshDatabase's per-test transaction wrapper so that
     * each Eloquent ::create() call in this test actually COMMITs for real,
     * matching production (no test ever wraps a real HTTP request in an
     * outer transaction). This is what makes the DEFERRED constraint
     * trigger's real firing behavior observable.
     */
    public function beginDatabaseTransaction()
    {
        // Intentionally no-op.
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            EnsureAccountingReverseAccess::class,
            EnsureAccountingWriteAccess::class,
            EnsureEntityAccess::class,
            ResolveEntityContext::class,
        ]);

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        // Since beginDatabaseTransaction() is a no-op, nothing rolls back.
        // Manually clean up everything this test wrote so state doesn't
        // leak into other test classes that run in the same process.
        try {
            DB::statement('TRUNCATE TABLE posting_audit_logs, journal_entry_links, journal_entry_lines, journal_entries, chart_of_accounts, users, user_company_access RESTART IDENTITY CASCADE');
        } catch (\Throwable $e) {
            // best-effort cleanup
        }

        parent::tearDown();
    }

    private function createAccount(string $code, string $type): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code,
            'name' => "Test {$code}",
            'type' => $type,
            'account_type' => $type,
            'is_active' => true,
        ]);
    }

    /**
     * Builds a real posted, 3-line, balanced journal entry via PostingEngine
     * (the canonical path), confirming PostingAuditLog + JournalEntryLink
     * rows exist for the ORIGINAL posting before we touch partialReverse().
     */
    private function createPostedMultiLineEntry(): JournalEntry
    {
        $expense = $this->createAccount('PR-EXP-'.uniqid(), 'expense');
        $ap = $this->createAccount('PR-AP-'.uniqid(), 'liability');
        $cash = $this->createAccount('PR-CASH-'.uniqid(), 'asset');

        $engine = app(PostingEngine::class);
        $documentId = random_int(1, 999999);

        $request = new PostingRequest(
            documentType: 'manual',
            documentId: $documentId,
            postingDate: now()->toDateString(),
            description: 'Partial reverse runtime test source entry',
            lines: [
                new PostingRequestLine(accountMappingKey: 'expense', accountId: $expense->id, debit: 600.00, credit: 0),
                new PostingRequestLine(accountMappingKey: 'ap', accountId: $ap->id, debit: 0, credit: 400.00),
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $cash->id, debit: 0, credit: 200.00),
            ],
            userId: $this->user->id,
        );

        $result = $engine->post($request);

        $this->assertTrue($result->success, 'Setup failed: PostingEngine did not accept the balanced source entry. Error: '.($result->error ?? 'unknown'));

        $entry = $result->journalEntry->fresh('lines');
        $this->assertEquals('posted', $entry->status);
        $this->assertCount(3, $entry->lines);

        return $entry;
    }

    public function test_partial_reverse_with_multiple_lines_via_real_http_request(): void
    {
        $entry = $this->createPostedMultiLineEntry();
        $originalLines = $entry->lines;

        $auditLogCountBefore = PostingAuditLog::count();
        $linkCountBefore = JournalEntryLink::count();

        fwrite(STDERR, "\n[CLAIM B] Source entry id={$entry->id}, lines=".$originalLines->count().", audit_logs_before={$auditLogCountBefore}, links_before={$linkCountBefore}\n");

        // Reverse TWO of the three lines (the multi-line scenario where deferred
        // trigger timing matters most): expense debit 600 line + AP credit 400 line.
        $expenseLine = $originalLines->firstWhere('debit', '>', 0);
        $apLine = $originalLines->firstWhere('account_id', '!=', $expenseLine->account_id);

        $payload = [
            'reversal_date' => now()->toDateString(),
            'reason' => 'Runtime test: multi-line partial reverse',
            'line_amounts' => [
                ['line_id' => $expenseLine->id, 'amount' => 600.00],
                ['line_id' => $apLine->id, 'amount' => (float) ($apLine->debit > 0 ? $apLine->debit : $apLine->credit)],
            ],
        ];

        fwrite(STDERR, '[CLAIM B] Request payload: '.json_encode($payload)."\n");

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/journal-entries/{$entry->id}/partial-reverse", $payload);

        fwrite(STDERR, '[CLAIM B] HTTP status: '.$response->getStatusCode()."\n");
        fwrite(STDERR, '[CLAIM B] HTTP body: '.$response->getContent()."\n");

        $reversalEntryId = $response->json('reversal_entry.id');

        // ── Determine outcome precisely ──
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->assertNotNull($reversalEntryId, 'Response was 2xx but contained no reversal_entry.id');

            $reversalLines = DB::table('journal_entry_lines')->where('journal_entry_id', $reversalEntryId)->get();
            $sumDebit = (float) $reversalLines->sum('debit');
            $sumCredit = (float) $reversalLines->sum('credit');

            $auditLogCountAfter = PostingAuditLog::count();
            $linkCountAfter = JournalEntryLink::count();

            $reversalEntryRow = DB::table('journal_entries')->where('id', $reversalEntryId)->first();

            fwrite(STDERR, "[CLAIM B] OUTCOME: 2xx success. reversal_entry_id={$reversalEntryId}, lines_persisted=".$reversalLines->count().", sum_debit={$sumDebit}, sum_credit={$sumCredit}\n");
            fwrite(STDERR, "[CLAIM B] audit_logs_after={$auditLogCountAfter} (delta=".($auditLogCountAfter - $auditLogCountBefore)."), links_after={$linkCountAfter} (delta=".($linkCountAfter - $linkCountBefore).")\n");
            fwrite(STDERR, '[CLAIM B] reversal entry legal_entity_id='.var_export($reversalEntryRow->legal_entity_id ?? null, true).' entity_book_id='.var_export($reversalEntryRow->entity_book_id ?? null, true)."\n");

            // Record what actually happened as assertions (documenting real behavior).
            $this->assertEquals(2, $reversalLines->count(), 'Both submitted lines should have persisted if the request succeeded.');
            $this->assertEqualsWithDelta($sumDebit, $sumCredit, 0.01, 'RUNTIME RESULT: reversal entry balance (debit vs credit).');

            $this->assertEquals(
                $auditLogCountBefore,
                $auditLogCountAfter,
                'RUNTIME RESULT: no PostingAuditLog row was created for the partial reversal (confirms static claim: partialReverse bypasses the audit trail).'
            );
            $this->assertEquals(
                $linkCountBefore,
                $linkCountAfter,
                'RUNTIME RESULT: no JournalEntryLink row was created for the partial reversal (confirms static claim).'
            );
            $this->assertNull($reversalEntryRow->legal_entity_id ?? null, 'RUNTIME RESULT: legal_entity_id was left null on the reversal entry.');
            $this->assertNull($reversalEntryRow->entity_book_id ?? null, 'RUNTIME RESULT: entity_book_id was left null on the reversal entry.');
        } else {
            // Failure path: verify original entry is untouched and no partial
            // reversal-entry/lines were left behind (or if they were, that's a
            // confirmed inconsistent-state finding).
            $errorBody = $response->json();
            fwrite(STDERR, '[CLAIM B] OUTCOME: request FAILED with status '.$response->getStatusCode().'. Error body: '.json_encode($errorBody)."\n");

            $orphanedReversalEntries = DB::table('journal_entries')->where('reversed_entry_id', $entry->id)->get();
            fwrite(STDERR, '[CLAIM B] Orphaned/partial reversal_entry rows found after failure: '.$orphanedReversalEntries->count()."\n");
            foreach ($orphanedReversalEntries as $orphan) {
                $orphanLines = DB::table('journal_entry_lines')->where('journal_entry_id', $orphan->id)->get();
                fwrite(STDERR, "[CLAIM B]   orphan entry id={$orphan->id} has ".$orphanLines->count()." lines persisted\n");
            }

            $originalStillIntact = JournalEntry::find($entry->id);
            $originalLinesStillIntact = DB::table('journal_entry_lines')->where('journal_entry_id', $entry->id)->count();
            fwrite(STDERR, "[CLAIM B] Original entry status after failed reversal: {$originalStillIntact->status}, lines count: {$originalLinesStillIntact}\n");

            $this->assertEquals('posted', $originalStillIntact->status, 'Original entry status must remain unaffected by a failed reversal.');
            $this->assertEquals(3, $originalLinesStillIntact, 'Original entry lines must remain unaffected by a failed reversal.');

            // This assertion documents whether the failure left an orphaned/partial
            // header row (case (c): inconsistent state) vs cleanly leaving nothing
            // behind (case (b): clean rollback/no-op).
            if ($orphanedReversalEntries->count() > 0) {
                $this->fail('RUNTIME RESULT: request failed AND left an orphaned reversal_entry header row behind (INCONSISTENT STATE - case (c)). See stderr log for details.');
            } else {
                $this->assertTrue(true, 'RUNTIME RESULT: request failed cleanly with no orphaned rows (case (b)).');
            }
        }
    }

    public function test_partial_reverse_single_unbalanced_line_via_real_http_request(): void
    {
        // Reversing only ONE line out of a multi-line entry produces a reversal
        // JournalEntry with exactly one line — which is, by construction,
        // unbalanced (debit != credit) for that journal_entry_id. This is the
        // scenario most likely to trip the deferred balance trigger, since
        // there is no offsetting line at all (not even a same-request one
        // inserted later).
        $entry = $this->createPostedMultiLineEntry();
        $expenseLine = $entry->lines->firstWhere('debit', '>', 0);

        $payload = [
            'reversal_date' => now()->toDateString(),
            'reason' => 'Runtime test: single-line (unbalanced) partial reverse',
            'line_amounts' => [
                ['line_id' => $expenseLine->id, 'amount' => 600.00],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/journal-entries/{$entry->id}/partial-reverse", $payload);

        fwrite(STDERR, "\n[CLAIM B - single line] HTTP status: ".$response->getStatusCode()."\n");
        fwrite(STDERR, '[CLAIM B - single line] HTTP body: '.$response->getContent()."\n");

        $reversalEntryId = $response->json('reversal_entry.id');

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $reversalEntryId)->get();
            $sumDebit = (float) $lines->sum('debit');
            $sumCredit = (float) $lines->sum('credit');

            fwrite(STDERR, "[CLAIM B - single line] OUTCOME: succeeded with status ".$response->getStatusCode().". lines=".$lines->count().", sum_debit={$sumDebit}, sum_credit={$sumCredit}\n");

            if (abs($sumDebit - $sumCredit) > 0.01) {
                fwrite(STDERR, "[CLAIM B - single line] CONFIRMED: an UNBALANCED journal entry (id={$reversalEntryId}) was created and the deferred trigger did NOT catch it within this request.\n");
            }

            $this->assertNotNull($reversalEntryId);
        } else {
            $errorBody = $response->getContent();
            fwrite(STDERR, "[CLAIM B - single line] OUTCOME: request FAILED/REJECTED with status ".$response->getStatusCode().". Body: {$errorBody}\n");

            $orphaned = DB::table('journal_entries')->where('reversed_entry_id', $entry->id)->get();
            fwrite(STDERR, '[CLAIM B - single line] Orphaned header rows after failure: '.$orphaned->count()."\n");
            foreach ($orphaned as $o) {
                $lc = DB::table('journal_entry_lines')->where('journal_entry_id', $o->id)->count();
                fwrite(STDERR, "[CLAIM B - single line]   orphan id={$o->id} line_count={$lc}\n");
            }
        }

        // This test is diagnostic/observational by design - it always "passes"
        // structurally; the real result is in the stderr log captured above,
        // which is reported verbatim in the final audit findings.
        $this->assertTrue(true);
    }

    /**
     * SUPPLEMENTARY / ISOLATED TEST of the deferred-trigger-timing question.
     *
     * The two tests above show that the REAL partialReverse() endpoint, as it
     * exists in the codebase today, fails on its very first INSERT (the
     * journal_entries header row) with a NOT NULL violation on entry_number
     * — a bug entirely unrelated to the deferred trigger — so it never
     * reaches the per-line INSERT loop at all. That means the original
     * speculation ("does the deferred balance trigger fire prematurely
     * between the two un-transacted per-line ::create() calls?") can never
     * actually be exercised through partialReverse() as currently written.
     *
     * To still give a precise, evidence-based answer to that specific
     * mechanism question (in case entry_number is fixed later, or for any
     * other un-transacted multi-line insert path), this test reproduces the
     * EXACT pattern partialReverse() uses — JournalEntry::create() followed
     * by a foreach loop of individual JournalEntryLine::create() calls, with
     * NO explicit DB::transaction() wrapper — but supplies a valid
     * entry_number so the header insert succeeds, isolating only the
     * transaction-timing behavior of the deferred constraint trigger.
     */
    public function test_isolated_untransacted_multiline_insert_pattern_and_deferred_trigger(): void
    {
        $entry = $this->createPostedMultiLineEntry();
        $originalLines = $entry->lines;

        $expenseLine = $originalLines->firstWhere('debit', '>', 0);
        $apLine = $originalLines->firstWhere('account_id', '!=', $expenseLine->account_id);

        $reversalLines = [
            [
                'account_id' => $expenseLine->account_id,
                'description' => 'Isolated pattern test line 1',
                'debit' => 0,
                'credit' => 600.00,
            ],
            [
                'account_id' => $apLine->account_id,
                'description' => 'Isolated pattern test line 2',
                'debit' => 400.00,
                'credit' => 0,
            ],
        ];

        fwrite(STDERR, "\n[CLAIM B - isolated pattern] About to reproduce partialReverse()'s exact insert pattern: JournalEntry::create() then a foreach of JournalEntryLine::create(), no DB::transaction() wrapper.\n");

        $exceptionThrown = null;
        $reversalEntry = null;
        try {
            // Exactly mirrors JournalEntriesController::partialReverse() lines 495-517,
            // except entry_number is supplied (fixing the unrelated bug found above)
            // so we can actually observe what the deferred trigger does.
            $reversalEntry = JournalEntry::create([
                'entry_number' => 'JE-ISOLATED-PATTERN-'.uniqid(),
                'journal_id' => $entry->journal_id,
                'entry_date' => now()->toDateString(),
                'reference' => "Isolated pattern test of #{$entry->id}",
                'description' => 'Isolated untransacted multi-line insert pattern test',
                'status' => 'posted',
                'is_reversal' => true,
                'reversed_entry_id' => $entry->id,
                'posted_at' => now(),
                'posted_by' => $this->user->id,
            ]);

            fwrite(STDERR, "[CLAIM B - isolated pattern] Header insert succeeded, id={$reversalEntry->id}. Now inserting line 1 (credit 600, alone - unbalanced at this point)...\n");

            foreach ($reversalLines as $i => $line) {
                \App\Models\JournalEntryLine::create([
                    'journal_entry_id' => $reversalEntry->id,
                    'account_id' => $line['account_id'],
                    'description' => $line['description'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
                fwrite(STDERR, '[CLAIM B - isolated pattern] Line '.($i + 1)." insert succeeded (debit={$line['debit']}, credit={$line['credit']}).\n");
            }
        } catch (\Throwable $e) {
            $exceptionThrown = $e;
            fwrite(STDERR, '[CLAIM B - isolated pattern] EXCEPTION during insert sequence: '.get_class($e).' - '.$e->getMessage()."\n");
        }

        if ($exceptionThrown !== null) {
            fwrite(STDERR, "[CLAIM B - isolated pattern] CONCLUSION: the deferred constraint trigger (or some other constraint) DID fire and reject an intermediate un-balanced insert state, even without an explicit wrapping transaction.\n");
            $partialLines = $reversalEntry ? DB::table('journal_entry_lines')->where('journal_entry_id', $reversalEntry->id)->count() : 0;
            fwrite(STDERR, "[CLAIM B - isolated pattern] Lines actually persisted before the exception: {$partialLines} (header row itself ".($reversalEntry ? 'DOES' : 'does NOT').' still exist - this is a partial/inconsistent state if header exists but not all lines).'."\n");
        } else {
            $finalLines = DB::table('journal_entry_lines')->where('journal_entry_id', $reversalEntry->id)->get();
            $sumDebit = (float) $finalLines->sum('debit');
            $sumCredit = (float) $finalLines->sum('credit');
            fwrite(STDERR, "[CLAIM B - isolated pattern] CONCLUSION: all inserts succeeded with NO exception. Final lines={$finalLines->count()}, sum_debit={$sumDebit}, sum_credit={$sumCredit}. The deferred trigger did NOT fire prematurely between the two un-transacted insert calls -- each PDO statement's implicit connection-level commit did not trip the DEFERRED constraint mid-sequence within this single PHP request/process.\n");
        }

        // Report the literal observed outcome as the test result.
        $this->assertTrue(true, 'Diagnostic test - real result captured in stderr log above.');
    }
}
