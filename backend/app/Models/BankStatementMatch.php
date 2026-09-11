<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BankStatementMatch extends Model
{
    use HasUuids;

    protected $fillable = ['bank_statement_transaction_id', 'journal_entry_line_id', 'reconciliation_id', 'matched_amount', 'match_type', 'confidence', 'match_reasons', 'review_reason', 'status', 'accepted_by', 'accepted_at', 'version', 'idempotency_key'];

    protected $casts = ['matched_amount' => 'decimal:8', 'confidence' => 'decimal:4', 'match_reasons' => 'array', 'accepted_at' => 'datetime'];
}
