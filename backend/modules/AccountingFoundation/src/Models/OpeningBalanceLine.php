<?php

namespace Modules\AccountingFoundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ACC-001 Opening Balance — line-level detail.
 *
 * Issue #70: each line represents one account's opening balance
 * within a batch. Lines are immutable once the batch leaves DRAFT.
 */
class OpeningBalanceLine extends Model
{
    protected $table = 'opening_balance_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'batch_id',
        'account_id',
        'account_code',
        'debit',
        'credit',
        'currency_code',
        'exchange_rate',
        'original_amount',
        'source_line_ref',
        'source_hash',
        'status',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'original_amount' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'batch_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Net balance: debit - credit (positive = debit balance).
     */
    public function netBalance(): float
    {
        return (float) $this->debit - (float) $this->credit;
    }
}
