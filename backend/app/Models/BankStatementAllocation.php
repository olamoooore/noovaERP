<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementAllocation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['bank_statement_transaction_id', 'allocation_type', 'external_reference', 'amount', 'sequence', 'raw_data'];

    protected $casts = ['amount' => 'decimal:8', 'raw_data' => 'array'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankStatementTransaction::class, 'bank_statement_transaction_id');
    }
}
