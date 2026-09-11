<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    use HasUuids;

    protected $fillable = ['bank_account_id', 'file_name', 'file_hash', 'storage_path', 'file_type', 'status', 'statement_start_date', 'statement_end_date', 'opening_balance', 'closing_balance', 'currency_code', 'row_count', 'transaction_count', 'duplicate_count', 'error_count', 'mapping', 'metadata', 'imported_by', 'imported_at'];

    protected $hidden = ['storage_path'];

    protected $casts = ['statement_start_date' => 'date', 'statement_end_date' => 'date', 'opening_balance' => 'decimal:8', 'closing_balance' => 'decimal:8', 'mapping' => 'array', 'metadata' => 'array', 'imported_at' => 'datetime'];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankStatementTransaction::class, 'import_id');
    }
}
