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

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            error: $error,
        );
    }
}
