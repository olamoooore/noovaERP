<?php

namespace App\Accounting\Contracts;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingResult;

interface PostingEngineInterface
{
    /**
     * Post a business document to the ledger.
     * Creates and posts a balanced journal entry atomically.
     */
    public function post(PostingRequest $request): PostingResult;

    /**
     * Reverse a previously posted journal entry.
     * Creates a reversal entry with swapped debits/credits.
     */
    public function reverse(int $journalEntryId, string $reason, ?int $approvedBy = null): PostingResult;

    /**
     * Preview the journal effect without committing.
     */
    public function preview(PostingRequest $request): array;
}
