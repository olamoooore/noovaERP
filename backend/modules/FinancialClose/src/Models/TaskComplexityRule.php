<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TaskComplexityRule extends Model
{
    use HasUuids;

    protected $table = 'fco_task_complexity_rules';

    protected $fillable = [
        'tenant_id',
        'task_type',
        'complexity_score',
        'conditions',
        'metadata',
    ];

    protected $casts = [
        'complexity_score' => 'integer',
        'conditions' => 'array',
        'metadata' => 'array',
    ];
}