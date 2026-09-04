<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chart of Accounts - Foundation of double-entry system
 */
class ChartOfAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'code',
        'name',
        'type',
        'subtype',
        'account_group',
        'parent_id',
        'description',
        'is_active',
        'is_system',
        'opening_balance',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'opening_balance' => 'decimal:2',
    ];

    protected $appends = [
        'level',
        'can_be_parent',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    /**
     * Compute account level based on code convention and tree position.
     *
     * L1 (Category): code ends with 000  — e.g. 1000 Assets, 2000 Liabilities
     * L2 (Group):    code ends with 00 but not 000  — e.g. 1100 Current Assets
     * L3 (Posting):  all other codes  — e.g. 1010 Cash, 1020 Bank
     *
     * Falls back to structural level (parent/children relationships) when
     * the code does not follow the convention.
     */
    public function getLevelAttribute(): int
    {
        $code = (string) $this->code;

        // Convention-based level from code suffix
        if (str_ends_with($code, '000')) {
            return 1;
        }
        if (str_ends_with($code, '00')) {
            return 2;
        }

        // Structural fallback
        $hasParent = $this->parent_id !== null;
        $hasChildren = $this->relationLoaded('children')
            ? $this->children->isNotEmpty()
            : $this->children()->exists();

        if (! $hasParent && $hasChildren) {
            return 1;
        }
        if ($hasParent && $hasChildren) {
            return 2;
        }

        return 3;
    }

    /**
     * Can this account be selected as a parent for another account?
     * Only L1 and L2 accounts (grouping accounts) can be parents.
     */
    public function getCanBeParentAttribute(): bool
    {
        return $this->level < 3;
    }

    public function getBalance(): float
    {
        $lines = $this->journalEntryLines()
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        $debit = (float) ($lines->total_debit ?? 0);
        $credit = (float) ($lines->total_credit ?? 0);

        // Assets & Expenses increase with debit
        if (in_array($this->type, ['asset', 'expense'])) {
            return $debit - $credit + (float) $this->opening_balance;
        }

        // Liabilities, Equity, Revenue increase with credit
        return $credit - $debit + (float) $this->opening_balance;
    }
}
