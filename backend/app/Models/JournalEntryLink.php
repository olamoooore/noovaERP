<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLink extends Model
{
    protected $fillable = [
        'source_document_type',
        'source_document_id',
        'journal_entry_id',
        'original_journal_entry_id',
        'reversal_journal_entry_id',
        'relation_type',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
