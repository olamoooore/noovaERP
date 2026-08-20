<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\PostingEngine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Tests\TenantTestCase;

class PostingEngineComprehensiveTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected PostingEngine $postingEngine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->postingEngine = app(PostingEngine::class);
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

    // ─── Post Method Tests ────────────────────────────────────────

    public function test_post_creates_balanced_journal_entry(): void
    {
        $debitAccount = $this->createAccount('PE-1100-' . uniqid(), 'asset');
        $creditAccount = $this->createAccount('PE-2100-' . uniqid(), 'liability');

        $request = new PostingRequest(
            documentType: 'manual',
            documentId: rand(1, 999999),
            description: 'Test posting',
            postingDate: now()->toDateString(),
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'debit',
                    accountId: $debitAccount->id,
                    debit: 1000.00,
                    credit: 0,
                ),
                new PostingRequestLine(
                    accountMappingKey: 'credit',
                    accountId: $creditAccount->id,
                    debit: 0,
                    credit: 1000.00,
                ),
            ]
        );

        $result = $this->postingEngine->post($request);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->journalEntry);
        $this->assertEquals('posted', $result->journalEntry->status);
    }

    public function test_post_rejects_unbalanced_entry(): void
    {
        $debitAccount = $this->createAccount('PE-UNB-' . uniqid(), 'asset');
        $creditAccount = $this->createAccount('PE-UNB2-' . uniqid(), 'liability');

        $request = new PostingRequest(
            documentType: 'manual',
            documentId: rand(1, 999999),
            description: 'Unbalanced test',
            postingDate: now()->toDateString(),
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'debit',
                    accountId: $debitAccount->id,
                    debit: 1000.00,
                    credit: 0,
                ),
                new PostingRequestLine(
                    accountMappingKey: 'credit',
                    accountId: $creditAccount->id,
                    debit: 0,
                    credit: 500.00,
                ),
            ]
        );

        $result = $this->postingEngine->post($request);

        $this->assertFalse($result->success);
    }

    public function test_post_is_idempotent(): void
    {
        $debitAccount = $this->createAccount('PE-IDEM-' . uniqid(), 'asset');
        $creditAccount = $this->createAccount('PE-IDEM2-' . uniqid(), 'liability');
        $documentId = (string) rand(1, 999999);

        $request = new PostingRequest(
            documentType: 'manual',
            documentId: $documentId,
            description: 'Idempotent test',
            postingDate: now()->toDateString(),
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'debit',
                    accountId: $debitAccount->id,
                    debit: 500.00,
                    credit: 0,
                ),
                new PostingRequestLine(
                    accountMappingKey: 'credit',
                    accountId: $creditAccount->id,
                    debit: 0,
                    credit: 500.00,
                ),
            ]
        );

        $result1 = $this->postingEngine->post($request);
        $result2 = $this->postingEngine->post($request);

        $this->assertTrue($result1->success);
        $this->assertTrue($result2->success);
        $this->assertEquals($result1->journalEntry->id, $result2->journalEntry->id);
    }

    // ─── Reverse Method Tests ─────────────────────────────────────

    public function test_reverse_creates_reversal_entry(): void
    {
        $debitAccount = $this->createAccount('PE-REV-' . uniqid(), 'asset');
        $creditAccount = $this->createAccount('PE-REV2-' . uniqid(), 'liability');

        $postRequest = new PostingRequest(
            documentType: 'manual',
            documentId: rand(1, 999999),
            description: 'Original entry',
            postingDate: now()->toDateString(),
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'debit',
                    accountId: $debitAccount->id,
                    debit: 750.00,
                    credit: 0,
                ),
                new PostingRequestLine(
                    accountMappingKey: 'credit',
                    accountId: $creditAccount->id,
                    debit: 0,
                    credit: 750.00,
                ),
            ]
        );

        $postResult = $this->postingEngine->post($postRequest);
        $originalEntry = $postResult->journalEntry;

        $reverseRequest = new ReversalRequest(
            originalEntryId: $originalEntry->id,
            reversalDate: now()->toDateString(),
            reason: 'Test reversal'
        );

        $reverseResult = $this->postingEngine->reverse($reverseRequest);

        $this->assertTrue($reverseResult->success);
        $this->assertNotNull($reverseResult->journalEntry);
        $this->assertEquals('posted', $reverseResult->journalEntry->status);
        $this->assertTrue($reverseResult->journalEntry->is_reversal);
    }

    public function test_reverse_rejects_draft_entry(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-DRAFT-001',
            'entry_date' => now()->toDateString(),
            'description' => 'Draft entry',
            'status' => 'draft',
        ]);

        $reverseRequest = new ReversalRequest(
            originalEntryId: $entry->id,
            reversalDate: now()->toDateString(),
            reason: 'Cannot reverse draft'
        );

        $this->expectException(\DomainException::class);
        $this->postingEngine->reverse($reverseRequest);
    }

    public function test_reverse_rejects_already_reversed(): void
    {
        $debitAccount = $this->createAccount('PE-DOUBLE-' . uniqid(), 'asset');
        $creditAccount = $this->createAccount('PE-DOUBLE2-' . uniqid(), 'liability');

        $postRequest = new PostingRequest(
            documentType: 'manual',
            documentId: rand(1, 999999),
            description: 'Double reverse test',
            postingDate: now()->toDateString(),
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'debit',
                    accountId: $debitAccount->id,
                    debit: 300.00,
                    credit: 0,
                ),
                new PostingRequestLine(
                    accountMappingKey: 'credit',
                    accountId: $creditAccount->id,
                    debit: 0,
                    credit: 300.00,
                ),
            ]
        );

        $postResult = $this->postingEngine->post($postRequest);

        $reverseRequest = new ReversalRequest(
            originalEntryId: $postResult->journalEntry->id,
            reversalDate: now()->toDateString(),
            reason: 'First reversal'
        );

        $this->postingEngine->reverse($reverseRequest);

        $reverseRequest2 = new ReversalRequest(
            originalEntryId: $postResult->journalEntry->id,
            reversalDate: now()->toDateString(),
            reason: 'Second reversal'
        );

        $this->expectException(\DomainException::class);
        $this->postingEngine->reverse($reverseRequest2);
    }

    // ─── Preview Method Tests ─────────────────────────────────────

    public function test_preview_returns_entry_without_posting(): void
    {
        $debitAccount = $this->createAccount('PE-PREV-' . uniqid(), 'asset');
        $creditAccount = $this->createAccount('PE-PREV2-' . uniqid(), 'liability');

        $request = new PostingRequest(
            documentType: 'manual',
            documentId: rand(1, 999999),
            description: 'Preview test',
            postingDate: now()->toDateString(),
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'debit',
                    accountId: $debitAccount->id,
                    debit: 200.00,
                    credit: 0,
                ),
                new PostingRequestLine(
                    accountMappingKey: 'credit',
                    accountId: $creditAccount->id,
                    debit: 0,
                    credit: 200.00,
                ),
            ]
        );

        $result = $this->postingEngine->preview($request);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->journalEntry);
        $this->assertEquals('draft', $result->journalEntry->status);
    }
}
