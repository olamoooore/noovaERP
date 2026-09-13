<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BankStatementOperation extends Model
{
    use HasUuids;

    protected $fillable = ['operation', 'idempotency_key', 'payload_hash', 'actor_id', 'status', 'response_type', 'response_id', 'response', 'correlation_id'];

    protected $casts = ['response' => 'array'];
}
