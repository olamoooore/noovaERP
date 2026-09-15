<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BankReconciliationReversal extends Model
{
    use HasUuids;

    protected $fillable = ['reconciliation_id', 'reason', 'status', 'requested_by', 'requested_at', 'approved_by', 'reversed_at', 'snapshot'];

    protected $casts = ['requested_at' => 'datetime', 'reversed_at' => 'datetime', 'snapshot' => 'array'];
}
