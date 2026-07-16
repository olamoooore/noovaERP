<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BackupAssignmentRule extends Model
{
    use HasUuids;

    protected $table = 'close_backup_assignment_rules';

    protected $fillable = [
        'tenant_id',
        'primary_user_id',
        'backup_user_id',
        'entity_id',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'is_active' => 'boolean',
    ];
}
