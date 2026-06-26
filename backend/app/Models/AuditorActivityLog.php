<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditorActivityLog extends Model
{
    use HasFactory;

    protected $table = 'auditor_activity_logs';

    protected $fillable = [
        'auditor_id',
        'tenant_id',
        'action',
        'resource_type',
        'resource_id',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Auditor::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
