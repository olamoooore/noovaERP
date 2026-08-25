<?php

/**
 * WS-08: Accounting Posting and Settlement Convergence
 *
 * Runtime PostingEngine tests (FIX-04).
 *
 * Coverage:
 *  - PostingEngine::post() creates a balanced JournalEntry with GL lines
 *  - PostingEngine::post() idempotency guard — same documentId only creates one JournalEntry
 *  - PostingEngine::post() rejects a date that falls in a locked period
 *  - JournalEntriesController::store() now validates lock-date before creating a draft (FIX-01)
 *  - JournalEntriesController::store() now validates fiscal period before creating a draft (FIX-01)
 *  - OcrDocument::unpost() no longer calls PostingEngine directly (FIX-03)
 *  - OcrPostingService::unpostDocument() owns the GL reversal (FIX-03)
 *  - PaymentController has no updateInvoiceFromPayment method (FIX-02 confirmed removed)
 */

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\DraftJournalRequest;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use App\Models\LockDate;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\OCRBookkeeping\Services\OcrPostingService;
use Modules\PaymentGateway\Controllers\PaymentController;
use Tests\TenantTestCase;

class WS08AccountingPostingTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Bind a tenant context and seed Spatie roles + permissions so the
        // PostingEngine::post() calls inside this test's PostingRequest
        // assertions pass the fail-closed MISSING_TENANT_CONTEXT and
        // INSUFFICIENT_PERMISSION gates introduced at SHA 91d50469.
        // authority_evaluation_snapshots.tenant_id is a UUID column — the
        // default 'test-tenant' placeholder is rejected, so pass a UUID.
        // The PostingEngine also requires a tenant user for audit attribution
        // (TenantUserHelper::requireTenantUserId) and makePostingRequest
        // references userId: 1 — seed that user before any posting runs.
        $this->bindTenantContextWithPermissions((string) Str::uuid());

        User::firstOrCreate(
            ['id' => 1],
            ['name' => 'WS08 Audit User', 'email' => 'ws08-audit@local', 'password' => bcrypt('password')],
        );
        User::find(1)?->assignRole('Admin');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** Return two test accounts (debit, credit) and a balanced PostingRequest DTO. */
    private function makePostingRequest(
        string $documentType = 'test_doc',
        int $documentId = 9001,
        string $date = '',
    ): PostingRequest {
        $date = $date ?: now()->addDays(30)->toDateString(); // safely in the future

        $debitAccount = ChartOfAccount::factory()->create(['type' => 'expense',   'is_active' => true]);
        $creditAccount = ChartOfAccount::factory()->create(['type' => 'liability', 'is_active' => true]);

        return new PostingRequest(
            documentType: $documentType,
            documentId: $documentId,
            postingDate: $date,
            description: 'WS-08 test posting',
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'expense',
                    accountId: $debitAccount->id,
                    debit: 500.00,
                    credit: 0.00,
                    description: 'Test debit line',
                ),
                new PostingRequestLine(
                    accountMappingKey: 'payable',
                    accountId: $creditAccount->id,
                    debit: 0.00,
                    credit: 500.00,
                    description: 'Test credit line',
                ),
            ],
            userId: 1,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Runtime — PostingEngine
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function posting_engine_creates_balanced_journal_entry(): void
    {
        $engine = app(PostingEngineInterface::class);
        $request = $this->makePostingRequest(documentId: 9001);

        $result = $engine->post($request);

        $this->assertTrue($result->success, "PostingEngine::post() failed: {$result->error}");
        $this->assertNotNull($result->journalEntry);

        $entry = $result->journalEntry;
        $this->assertEquals('posted', $entry->status);

        // Lines must be balanced
        $totalDebit = $entry->lines->sum('debit');
        $totalCredit = $entry->lines->sum('credit');
        $this->assertEqualsWithDelta(500.00, $totalDebit, 0.01, 'Total debit mismatch');
        $this->assertEqualsWithDelta(500.00, $totalCredit, 0.01, 'Total credit mismatch');

        // JournalEntryLink must exist (idempotency record)
        $this->assertDatabaseHas('journal_entry_links', [
            'source_document_type' => 'test_doc',
            'source_document_id' => 9001,
            'relation_type' => 'post',
        ]);
    }

    /** @test */
    public function posting_engine_create_draft_defaults_currency_fields_for_manual_lines(): void
    {
        $engine = app(PostingEngineInterface::class);

        $debitAccount = ChartOfAccount::factory()->create(['type' => 'expense', 'is_active' => true]);
        $creditAccount = ChartOfAccount::factory()->create(['type' => 'liability', 'is_active' => true]);

        $request = DraftJournalRequest::fromArray([
            'entry_date' => now()->addDays(30)->toDateString(),
            'description' => 'Manual journal without explicit currency fields',
            'lines' => [
                [
                    'account_id' => $debitAccount->id,
                    'debit' => 125.50,
                    'credit' => 0,
                    'description' => 'Debit line',
                ],
                [
                    'account_id' => $creditAccount->id,
                    'debit' => 0,
                    'credit' => 125.50,
                    'description' => 'Credit line',
                ],
            ],
        ]);

        $result = $engine->createDraft($request);

        $this->assertTrue($result->success, "PostingEngine::createDraft() failed: {$result->error}");
        $this->assertNotNull($result->journalEntry);

        $lines = JournalEntryLine::where('journal_entry_id', $result->journalEntry->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $lines);
        // Engine defaults to the tenant currency setting (fallback SGD) —
        // assert against the resolved default, not a hardcoded code.
        $expectedCurrency = \App\Models\Setting::getValue('currency', ['code' => 'SGD'])['code'] ?? 'SGD';
        $this->assertSame($expectedCurrency, $lines[0]->currency_code);
        $this->assertSame($expectedCurrency, $lines[1]->currency_code);
        $this->assertEqualsWithDelta(125.50, (float) $lines[0]->original_amount, 0.01);
        $this->assertEqualsWithDelta(125.50, (float) $lines[1]->original_amount, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $lines[0]->exchange_rate, 0.000001);
        $this->assertEqualsWithDelta(1.0, (float) $lines[1]->exchange_rate, 0.000001);
    }

    /** @test */
    public function posting_engine_is_idempotent_for_same_document_id(): void
    {
        $engine = app(PostingEngineInterface::class);
        $request1 = $this->makePostingRequest(documentId: 9002);
        $request2 = $this->makePostingRequest(documentId: 9002); // identical document_id

        $result1 = $engine->post($request1);
        $result2 = $engine->post($request2);

        $this->assertTrue($result1->success, "First post failed: {$result1->error}");
        $this->assertTrue($result2->success, "Second post failed: {$result2->error}");

        // Both results must point to the same JournalEntry
        $this->assertEquals($result1->journalEntryId, $result2->journalEntryId);

        // Only one journal_entry_link record must exist
        $this->assertDatabaseCount('journal_entry_links', 1);

        // Only one JournalEntry created
        $this->assertEquals(
            1,
            JournalEntry::where('id', $result1->journalEntryId)->count(),
        );
    }

    /** @test */
    public function posting_engine_rejects_date_in_locked_period(): void
    {
        // Lock everything up to (and including) last month
        $lockThrough = now()->subMonth()->endOfMonth()->toDateString();

        $lockSetter = User::firstOrCreate(
            ['email' => 'ws08-locker@local'],
            ['name' => 'WS08 Locker', 'password' => bcrypt('password')],
        );

        LockDate::create([
            'lock_type' => 'journal_entries',
            'lock_date' => $lockThrough,
            'is_active' => true,
            'reason' => 'WS-08 test lock',
            'set_by' => $lockSetter->id, // column is NOT NULL + FK to users
        ]);

        // Try to post a date that is <= lockThrough → must be rejected
        $lockedDate = now()->subMonth()->startOfMonth()->toDateString();
        $engine = app(PostingEngineInterface::class);
        $request = $this->makePostingRequest(documentId: 9003, date: $lockedDate);

        $result = $engine->post($request);

        $this->assertFalse($result->success, 'PostingEngine should have rejected locked-period date');
        $this->assertNotEmpty($result->error);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Structural — FIX-01 (JournalEntriesController::store validation)
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function journal_entries_controller_store_calls_lock_date_validation(): void
    {
        $controllerPath = base_path('app/Http/Controllers/Api/JournalEntriesController.php');
        $source = file_get_contents($controllerPath);

        $this->assertStringContainsString(
            'lockDateService->validateTransactionDate(',
            $source,
            'FIX-01: JournalEntriesController::store() must call lockDateService->validateTransactionDate()',
        );
    }

    /** @test */
    public function journal_entries_controller_store_calls_fiscal_period_validation(): void
    {
        $controllerPath = base_path('app/Http/Controllers/Api/JournalEntriesController.php');
        $source = file_get_contents($controllerPath);

        $this->assertStringContainsString(
            'fiscalValidator->validateDate(',
            $source,
            'FIX-01: JournalEntriesController::store() must call fiscalValidator->validateDate()',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Structural — FIX-02 (updateInvoiceFromPayment removed)
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function payment_controller_has_no_update_invoice_from_payment_method(): void
    {
        $this->assertFalse(
            method_exists(PaymentController::class, 'updateInvoiceFromPayment'),
            'FIX-02: PaymentController::updateInvoiceFromPayment() must be removed',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Structural — FIX-03 (OCR GL reversal lives in OcrPostingService)
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function ocr_document_unpost_does_not_call_posting_engine_directly(): void
    {
        $modelPath = base_path('modules/OCRBookkeeping/src/Models/OcrDocument.php');
        $source = file_get_contents($modelPath);

        $this->assertStringNotContainsString(
            'app(\App\Accounting\Contracts\PostingEngineInterface::class)',
            $source,
            'FIX-03: OcrDocument::unpost() must not call PostingEngine directly',
        );

        $this->assertStringNotContainsString(
            'app(PostingEngineInterface::class)',
            $source,
            'FIX-03: OcrDocument::unpost() must not resolve PostingEngine via app()',
        );
    }

    /** @test */
    public function ocr_posting_service_has_unpost_document_method(): void
    {
        $this->assertTrue(
            method_exists(OcrPostingService::class, 'unpostDocument'),
            'FIX-03: OcrPostingService must expose unpostDocument() method',
        );
    }
}
