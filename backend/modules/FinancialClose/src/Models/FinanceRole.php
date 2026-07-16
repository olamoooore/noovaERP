<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceRole extends Model
{
    use HasUuids;

    protected $table = 'org_finance_roles';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(FinanceRoleAssignment::class, 'role_id');
    }
}
