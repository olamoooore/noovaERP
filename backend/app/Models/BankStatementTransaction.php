<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementTransaction extends Model
{
    use HasUuids;

    protected $fillable = ['import_id', 'bank_account_id', 'transaction_key', 'source_row', 'value_date', 'transaction_date', 'transaction_time', 'description', 'bank_reference', 'customer_reference', 'customer_code', 'amount', 'currency_code', 'running_balance', 'status', 'matched_amount', 'residual_amount', 'needs_review', 'raw_data'];

    protected $casts = ['value_date' => 'date', 'transaction_date' => 'date', 'amount' => 'decimal:8', 'running_balance' => 'decimal:8', 'matched_amount' => 'decimal:8', 'residual_amount' => 'decimal:8', 'needs_review' => 'boolean', 'raw_data' => 'array'];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'import_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BankStatementAllocation::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankStatementMatch::class);
    }
}
