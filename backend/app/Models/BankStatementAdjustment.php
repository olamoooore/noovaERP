<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementAdjustment extends Model
{
    use HasUuids;

    protected $fillable = ['bank_statement_transaction_id', 'contra_account_id', 'amount', 'description', 'reason', 'status', 'requested_by', 'approved_by', 'journal_entry_line_id', 'idempotency_key', 'approved_at'];

    protected $casts = ['amount' => 'decimal:8', 'approved_at' => 'datetime'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankStatementTransaction::class, 'bank_statement_transaction_id');
    }
}
