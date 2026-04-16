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
}
