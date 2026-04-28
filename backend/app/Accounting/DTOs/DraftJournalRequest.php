<?php

namespace App\Accounting\DTOs;

class DraftJournalLine
{
    public function __construct(
        public readonly ?int $accountId,
        public readonly float $debit,
        public readonly float $credit,
        public readonly ?string $description = null,
        public readonly ?string $reference = null,
        public readonly ?string $currencyCode = null,
        public readonly ?float $originalAmount = null,
        public readonly ?float $exchangeRate = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $projectId = null,
        public readonly ?int $locationId = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            accountId: $data['account_id'] ?? null,
            debit: (float) ($data['debit'] ?? 0),
            credit: (float) ($data['credit'] ?? 0),
            description: $data['description'] ?? null,
            reference: $data['reference'] ?? null,
            currencyCode: $data['currency_code'] ?? null,
            originalAmount: isset($data['original_amount']) ? (float) $data['original_amount'] : null,
            exchangeRate: isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            departmentId: $data['department_id'] ?? null,
            projectId: $data['project_id'] ?? null,
            locationId: $data['location_id'] ?? null,
        );
    }
}

class DraftJournalRequest
{
    /**
     * @param DraftJournalLine[] $lines
     */
    public function __construct(
        public readonly ?int $journalId,
        public readonly string $entryDate,
        public readonly string $description,
        public readonly array $lines,
        public readonly ?string $reference = null,
        public readonly ?int $userId = null,
        public readonly ?int $legalEntityId = null,
        public readonly ?int $entityBookId = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $lines = array_map(
            fn($line) => DraftJournalLine::fromArray($line),
            $data['lines'] ?? []
        );

        return new self(
            journalId: $data['journal_id'] ?? null,
            entryDate: $data['entry_date'],
            description: $data['description'],
            lines: $lines,
            reference: $data['reference'] ?? null,
            userId: $data['user_id'] ?? null,
            legalEntityId: $data['legal_entity_id'] ?? null,
            entityBookId: $data['entity_book_id'] ?? null,
        );
    }
}

class DraftJournalResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?\App\Models\JournalEntry $journalEntry = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(\App\Models\JournalEntry $entry): self
    {
        return new self(true, $entry);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}