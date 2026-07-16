<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExceptionRegister extends Model
{
    use HasUuids;

    protected $table = 'fco_exception_register';

    protected $fillable = [
        'tenant_id',
        'override_request_id',
        'entity_id',
        'exception_type',
        'status',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function overrideRequest(): BelongsTo
    {
        return $this->belongsTo(OverrideRequest::class, 'override_request_id');
    }
}
