<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BankReconciliationScope extends Model
{
    use HasUuids;

    protected $fillable = ['reconciliation_id', 'bank_account_id', 'previous_statement_end_date', 'statement_start_date', 'statement_end_date', 'import_ids', 'opening_balance', 'closing_balance', 'currency_code', 'currency_tolerance', 'status', 'version', 'created_by', 'approved_by', 'approved_at'];

    protected $casts = ['import_ids' => 'array', 'statement_start_date' => 'date', 'statement_end_date' => 'date', 'previous_statement_end_date' => 'date', 'approved_at' => 'datetime'];
}
