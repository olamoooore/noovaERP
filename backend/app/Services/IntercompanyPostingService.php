<?php

namespace App\Services;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Accounting\Services\AccountRoleResolver;
use Illuminate\Support\Facades\DB;

/**
 * Intercompany transaction support.
 *
 * When a posting involves accounts across two legal entities,
 * automatically generates balancing intercompany entries using
 * INTERCOMPANY_DUE_FROM / INTERCOMPANY_DUE_TO roles.
 */
class IntercompanyPostingService
{
    public function __construct(
        private readonly PostingEngine $postingEngine,
        private readonly AccountRoleResolver $roleResolver,
    ) {}

    /**
     * Post an intercompany transaction between two legal entities.
     *
     * Creates:
     * 1. The original entry in the source entity
     * 2. A balancing intercompany DUE-FROM entry in the source entity
     * 3. A balancing intercompany DUE-TO entry in the target entity
     * 4. The mirror entry in the target entity
     */
    public function post(
        PostingRequest $sourceRequest,
        int $targetEntityId,
        int $targetBookId,
        array $targetLines,
        string $description,
    ): array {
        return DB::transaction(function () use ($sourceRequest, $targetEntityId, $targetBookId, $targetLines, $description) {
            // 1. Post original entry in source entity
            $sourceResult = $this->postingEngine->post($sourceRequest);
            if (!$sourceResult->success) {
                throw new \RuntimeException("Source posting failed: {$sourceResult->error}");
            }

            $amount = array_sum(array_map(fn($l) => $l->debit, $sourceRequest->lines));

            // 2. Create intercompany DUE-FROM in source entity (receivable from target)
            $dueFromRequest = new PostingRequest(
                documentType: 'intercompany',
                documentId: $sourceResult->journalEntry->id,
                postingDate: $sourceRequest->postingDate,
                description: "IC Due From: {$description}",
                lines: [
                    new PostingRequestLine(
                        accountMappingKey: 'INTERCOMPANY_DUE_FROM',
                        debit: $amount,
                        credit: 0,
                    ),
                    new PostingRequestLine(
                        accountMappingKey: 'INTERCOMPANY_DUE_FROM',
                        debit: 0,
                        credit: $amount,
                        description: 'IC offset',
                    ),
                ],
                reference: "IC-{$sourceResult->journalEntry->entry_number}",
                userId: $sourceRequest->userId,
                legalEntityId: $sourceRequest->legalEntityId,
                entityBookId: $sourceRequest->entityBookId,
            );
            $dueFromResult = $this->postingEngine->post($dueFromRequest);

            // 3. Create intercompany DUE-TO in target entity (payable to source)
            $dueToRequest = new PostingRequest(
                documentType: 'intercompany',
                documentId: $sourceResult->journalEntry->id,
                postingDate: $sourceRequest->postingDate,
                description: "IC Due To: {$description}",
                lines: [
                    new PostingRequestLine(
                        accountMappingKey: 'INTERCOMPANY_DUE_TO',
                        debit: 0,
                        credit: $amount,
                    ),
                    new PostingRequestLine(
                        accountMappingKey: 'INTERCOMPANY_DUE_TO',
                        debit: $amount,
                        credit: 0,
                        description: 'IC offset',
                    ),
                ],
                reference: "IC-{$sourceResult->journalEntry->entry_number}",
                userId: $sourceRequest->userId,
                legalEntityId: $targetEntityId,
                entityBookId: $targetBookId,
            );
            $dueToResult = $this->postingEngine->post($dueToRequest);

            // 4. Post mirror entry in target entity
            $targetRequest = new PostingRequest(
                documentType: $sourceRequest->documentType,
                documentId: $sourceRequest->documentId,
                postingDate: $sourceRequest->postingDate,
                description: "IC Mirror: {$description}",
                lines: array_map(fn($l) => new PostingRequestLine(
                    accountMappingKey: $l['account_mapping_key'] ?? 'general_expense',
                    accountId: $l['account_id'] ?? null,
                    debit: (float) ($l['debit'] ?? 0),
                    credit: (float) ($l['credit'] ?? 0),
                    description: $l['description'] ?? null,
                ), $targetLines),
                reference: "IC-{$sourceResult->journalEntry->entry_number}",
                userId: $sourceRequest->userId,
                legalEntityId: $targetEntityId,
                entityBookId: $targetBookId,
            );
            $targetResult = $this->postingEngine->post($targetRequest);

            return [
                'source_entry'    => $sourceResult->journalEntry,
                'due_from_entry'  => $dueFromResult->journalEntry,
                'due_to_entry'    => $dueToResult->journalEntry,
                'target_entry'    => $targetResult->journalEntry,
            ];
        });
    }
}
