<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OverrideRequest extends Model
{
    use HasUuids;

    protected $table = 'fco_override_requests';

    protected $fillable = [
        'tenant_id',
        'entity_type',
        'entity_id',
        'override_type',
        'status',
        'requested_by',
        'reason',
        'reason_code',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function approvals(): HasMany
    {
        return $this->hasMany(OverrideApproval::class, 'override_request_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ExceptionRegister::class, 'override_request_id');
    }
}
