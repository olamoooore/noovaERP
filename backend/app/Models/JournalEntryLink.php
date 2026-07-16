<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLink extends Model
{
    protected $fillable = [
        'tenant_id',
        'source_document_type',
        'source_document_id',
        'journal_entry_id',
        'original_journal_entry_id',
        'reversal_journal_entry_id',
        'relation_type',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function scopePostLinks($query)
    {
        return $query->where('relation_type', 'post');
    }

    public function scopeReversalLinks($query)
    {
        return $query->where('relation_type', 'reversal');
    }

    public static function buildPostIdempotencyKey(string $documentType, int $documentId): string
    {
        return $documentType.':'.$documentId;
    }

    public static function buildReversalIdempotencyKey(int $originalJournalEntryId): string
    {
        return 'reversal:'.$originalJournalEntryId;
    }
}
