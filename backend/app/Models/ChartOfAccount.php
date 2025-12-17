<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function getBalance(): float
    {
        $lines = $this->journalEntryLines()
            ->whereHas('journalEntry', fn($q) => $q->where('status', 'posted'))
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
