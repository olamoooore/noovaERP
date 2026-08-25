<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\IntercompanyPostingService;
use Tests\TestCase;

/**
 * Runtime tests for issue #49 — IntercompanyPostingService runtime quarantine.
 *
 * Verifies the service throws the canonical reason code BEFORE reaching the
 * PostingEngine, so any accidental wiring fails fast with a deterministic
 * reason rather than silently posting defective intercompany journals.
 */
class Issue49IntercompanyQuarantineTest extends TestCase
{
    public function test_post_throws_canonical_quarantine_reason_before_posting_engine(): void
    {
        $service = app(IntercompanyPostingService::class);
        $sourceRequest = new \App\Accounting\DTOs\PostingRequest(
            documentType: 'invoice',
            documentId: 1,
            postingDate: now()->toDateString(),
            description: 'Quarantine test',
            lines: [],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(IntercompanyPostingService::QUARANTINE_REASON);

        $service->post($sourceRequest, 2, 1, [], 'test');
    }

    public function test_quarantine_reason_constant_is_stable(): void
    {
        $this->assertSame(
            'INTERCOMPANY_CAPABILITY_QUARANTINED',
            IntercompanyPostingService::QUARANTINE_REASON
        );
    }
}
