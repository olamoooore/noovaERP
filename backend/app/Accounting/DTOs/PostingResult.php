<?php

namespace App\Accounting\DTOs;

use App\Models\JournalEntry;

class PostingResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $journalEntryId = null,
        public readonly ?JournalEntry $journalEntry = null,
        public readonly ?int $auditLogId = null,
        public readonly ?string $error = null,
        // #278: canonical ReasonCode value when the failure is a governed
        // block (e.g. TAX_PERIOD_LOCKED), so callers need not parse $error.
        public readonly ?string $reasonCode = null,
    ) {}

    public static function success(JournalEntry $entry, ?int $auditLogId = null): self
    {
        return new self(
            success: true,
            journalEntryId: $entry->id,
            journalEntry: $entry,
            auditLogId: $auditLogId,
        );
    }

    public static function failure(string $error, ?string $reasonCode = null): self
    {
        return new self(
            success: false,
            error: $error,
            reasonCode: $reasonCode,
        );
    }
}
