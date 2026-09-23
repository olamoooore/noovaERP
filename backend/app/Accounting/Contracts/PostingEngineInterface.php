<?php

namespace App\Accounting\Contracts;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\DTOs\ReversalRequest;

interface PostingEngineInterface
{
    /**
     * Post a business document to the ledger.
     * Creates and posts a balanced journal entry atomically.
     */
    public function post(PostingRequest $request): PostingResult;

    /**
     * Reverse a previously posted journal entry.
     *
     * Accepts a typed ReversalRequest that enforces the reversal date,
     * reason, actor, and approver — preventing self-approval and ensuring
     * the reversal date is validated against lock + fiscal controls.
     */
    public function reverse(ReversalRequest $request): PostingResult;

    /**
     * Preview the journal effect without committing.
     */
    public function preview(PostingRequest $request): array;

    /**
     * Create a draft journal entry for manual journals.
     * Draft entries are saved with status='draft' and can be posted later.
     * All creation MUST go through PostingEngine to maintain audit trail.
     */
    public function createDraft(\App\Accounting\DTOs\DraftJournalRequest $request): \App\Accounting\DTOs\DraftJournalResult;

    /**
     * Create a replacement draft journal entry based on an original entry.
     * Used for correction workflows that create replacement documents.
     */
    public function createReplacementDraft(\App\Models\JournalEntry $original, \App\Services\CorrectionRequest $request): \App\Accounting\DTOs\DraftJournalResult;

    /**
     * Issue #68 (HR68-02): ACC-002-owned retirement of a posting reference.
     *
     * Marks the original 'post' JournalEntryLink superseded (preserving it as
     * history — never a physical delete) so a subsequent posting for the same
     * source is no longer treated as a duplicate. Called by ACC-011 period
     * reopen; the authoritative mutation is owned here, not by the caller.
     */
    public function retirePostingReference(int $originalJournalEntryId, ?int $reversalJournalEntryId, string $reason): void;
}
