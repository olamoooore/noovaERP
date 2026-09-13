<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BankStatementAuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['event', 'actor_id', 'bank_account_id', 'import_id', 'statement_transaction_id', 'match_id', 'reconciliation_id', 'reason', 'before_state', 'after_state', 'metadata', 'correlation_id', 'created_at'];

    protected $casts = ['before_state' => 'array', 'after_state' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Bank statement audit events are immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Bank statement audit events are immutable.'));
    }
}
