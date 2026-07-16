<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceRoleAssignment extends Model
{
    use HasUuids;

    protected $table = 'org_finance_role_assignments';

    protected $fillable = [
        'tenant_id',
        'role_id',
        'user_id',
        'entity_id',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(FinanceRole::class, 'role_id');
    }
}
