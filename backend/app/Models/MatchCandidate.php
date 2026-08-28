<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_line_id',
        'journal_entry_line_id',
        'match_type',
        'confidence',
        'reason',
        'accepted',
        'accepted_at',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'accepted' => 'boolean',
        'accepted_at' => 'datetime',
    ];

    public function bankStatementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class);
    }

    public function journalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class);
    }
}
