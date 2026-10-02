<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tax extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'jurisdiction',
        'regime',
        'scope',
        'treatment',
        'type',
        'rate',
        'is_active',
        'is_default',
        'is_inclusive',
        'effective_from',
        'effective_to',
        'creditable',
        'blocked_expense_account_id',
        'version',
        'pack_version',
        'pack_snapshot',
        'superseded_by_id',
        'lifecycle_state',
        'submitted_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'version' => 'integer',
        'pack_version' => 'integer',
        'pack_snapshot' => 'array',
        'superseded_by_id' => 'integer',
        'approved_at' => 'datetime',
        'creditable' => 'boolean',
        'is_active' => 'bool',
        'is_default' => 'bool',
        'is_inclusive' => 'bool',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];
}
