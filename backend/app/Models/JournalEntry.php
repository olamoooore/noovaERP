<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal Entry - Immutable once posted
 * Enforces debit = credit rule
 */
class JournalEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'entry_number',
        'entry_date',
        'type',
        'reference',
        'description',
        'status',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
    ];

    protected $appends = ['is_balanced'];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getTotalDebit(): float
    {
        return (float) $this->lines()->sum('debit');
    }

    public function getTotalCredit(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    public function getIsBalancedAttribute(): bool
    {
        return abs($this->getTotalDebit() - $this->getTotalCredit()) < 0.01;
    }

    public function post(): void
    {
        if ($this->status === 'posted') {
            throw new \Exception('Journal entry already posted');
        }

        if (!$this->is_balanced) {
            throw new \Exception('Journal entry must balance before posting');
        }

        $this->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ]);
    }
}
