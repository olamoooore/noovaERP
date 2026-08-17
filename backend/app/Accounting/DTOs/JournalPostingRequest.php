<?php

namespace App\Accounting\DTOs;

/**
 * JournalPostingRequest — explicit journal-capable workflow contract.
 * ADR-005 §14 — restricted to explicit journal-capable workflows and permissions.
 *
 * Unlike DomainPostingRequest, this carries explicit debit/credit lines
 * and is only used by authorised journal workflows.
 */
class JournalPostingRequest
{
    /**
     * @param  JournalPostingLine[]  $journalLines  Explicit debit/credit lines
     */
    public function __construct(
        public readonly string $journalPostingRequestId,
        public readonly string $idempotencyKey,
        public readonly string $tenantId,
        public readonly string $entityId,
        public readonly string $accountingBookId,
        public readonly string $journalType,
        public readonly string $businessDate,
        public readonly string $postingDate,
        public readonly string $currencyCode,
        public readonly array $journalLines,
        public readonly ?string $workflowInstanceId = null,
        public readonly ?string $approvalReference = null,
        public readonly ?string $authorityEvaluationId = null,
        public readonly array $supportingEvidenceRefs = [],
        public readonly ?string $createdBy = null,
        public readonly ?string $description = null,
    ) {}

    public static function generateRequestId(): string
    {
        return 'JPR-' . uniqid('', true);
    }

    /**
     * Validate that debits equal credits.
     */
    public function isBalanced(): bool
    {
        $totalDebit = array_sum(array_map(fn (JournalPostingLine $l) => $l->debitAmount, $this->journalLines));
        $totalCredit = array_sum(array_map(fn (JournalPostingLine $l) => $l->creditAmount, $this->journalLines));

        return abs($totalDebit - $totalCredit) < 0.01;
    }
}

/**
 * An explicit journal line with debit or credit amount.
 */
class JournalPostingLine
{
    public function __construct(
        public readonly string $ledgerAccountId,
        public readonly float $debitAmount = 0,
        public readonly float $creditAmount = 0,
        public readonly ?float $transactionCurrencyAmount = null,
        public readonly array $dimensions = [],
        public readonly ?string $taxReference = null,
        public readonly ?string $narrative = null,
    ) {
        if ($debitAmount > 0 && $creditAmount > 0) {
            throw new \InvalidArgumentException('A line cannot have both debit and credit amounts.');
        }
        if ($debitAmount <= 0 && $creditAmount <= 0) {
            throw new \InvalidArgumentException('A line must have either a debit or credit amount.');
        }
    }
}
