<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OverrideApproval extends Model
{
    use HasUuids;

    protected $table = 'fco_override_approvals';

    protected $fillable = [
        'tenant_id',
        'override_request_id',
        'approved_by',
        'status',
        'notes',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function overrideRequest(): BelongsTo
    {
        return $this->belongsTo(OverrideRequest::class, 'override_request_id');
    }
}
