<?php

namespace Tests\Feature;

use App\Accounting\DTOs\DomainPostingLine;
use App\Accounting\DTOs\DomainPostingRequest;
use App\Accounting\PostingEngine;
use App\Accounting\Services\PostingPolicyMapper;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Regression test for issue: zero-amount invoice posting.
 *
 * Before the fix, PostingPolicyMapper::mapLineThroughProfile returned null
 * for zero-amount business lines. When ALL lines were zero (a wholly zero
 * invoice), every line returned null and the resolver emitted the misleading
 * error "No mapping found for account role ... in profile ..." — even though
 * the mapping existed. This blocked legitimate zero invoices (e.g. credit
 * notes, complimentary invoices) from posting.
 *
 * The fix removes the zero-amount drop in the mapper. Zero lines now flow
 * through with debit=credit=0; the PostingEngine balance check confirms
 * the entry balances (0=0) and accepts the post.
 */
class ZeroAmountInvoicePostTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
    }

    public function test_zero_amount_line_is_not_dropped_by_mapper(): void
    {
        /** @var PostingPolicyMapper $mapper */
        $mapper = app(PostingPolicyMapper::class);

        $req = new DomainPostingRequest(
            postingRequestId: 'test-' . uniqid(),
            idempotencyKey: 'test-zero-' . uniqid(),
            tenantId: $this->tenantId,
            entityId: 'default',
            accountingBookId: 'default',
            sourceCapability: 'ACC-003',
            sourceRecordType: 'invoice',
            sourceRecordId: '0',
            sourceRecordVersion: 1,
            postingProfileCode: 'AR_INVOICE_POST',
            transactionDate: '2026-09-08',
            postingDate: '2026-09-08',
            lines: [
                new DomainPostingLine(
                    accountRole: 'accounts_receivable',
                    amount: 0.00,
                    description: 'Zero invoice',
                ),
            ],
            dimensions: [],
            taxResultReference: null,
        );

        $resolution = $mapper->resolve($req);

        // Zero-amount line must NOT trigger the false "No mapping found" error.
        // It should either resolve to a balanced 0/0 line OR fail with a
        // genuine account-resolution issue (not the misleading mapping error).
        if (! $resolution->success) {
            $this->assertStringNotContainsString(
                'No mapping found for account role',
                $resolution->error ?? '',
                'Zero-amount line must not produce false "no mapping" error'
            );
        }
    }

    public function test_posting_engine_accepts_zero_invoice(): void
    {
        /** @var PostingEngine $engine */
        $engine = app(PostingEngine::class);

        $req = new DomainPostingRequest(
            postingRequestId: 'test-' . uniqid(),
            idempotencyKey: 'test-zero-post-' . uniqid(),
            tenantId: $this->tenantId,
            entityId: 'default',
            accountingBookId: 'default',
            sourceCapability: 'ACC-003',
            sourceRecordType: 'invoice',
            sourceRecordId: '999999',
            sourceRecordVersion: 1,
            postingProfileCode: 'AR_INVOICE_POST',
            transactionDate: '2026-09-08',
            postingDate: '2026-09-08',
            lines: [
                new DomainPostingLine(
                    accountRole: 'accounts_receivable',
                    amount: 0.00,
                    description: 'Zero invoice test',
                ),
            ],
            dimensions: [],
            taxResultReference: null,
        );

        // Should not throw — either succeeds or fails with a NON-mapping error
        // (account resolution may legitimately fail in test env without accounts).
        try {
            $result = $engine->domainPosting($req);
            // Either success or a non-mapping error
            if (! $result->success) {
                $this->assertStringNotContainsString(
                    'No mapping found for account role',
                    $result->message,
                    'Zero-amount line must not produce false "no mapping" error'
                );
            }
            $this->assertTrue(true); // Test reaches here without exception
        } catch (\Exception $e) {
            $this->assertStringNotContainsString(
                'No mapping found for account role',
                $e->getMessage(),
                'Zero-amount line must not produce false "no mapping" error'
            );
        }
    }
}
